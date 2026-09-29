<?php

namespace App\Services;

use App\Exceptions\ItemException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The product catalogue and its batches.
 *
 * Stock is never written here. Receiving goods creates the batch row and then
 * hands the actual movement to StockService, which appends the ledger row and
 * refreshes the cache (RULE 2). That split is the whole point: if this class
 * could write a quantity, there would be two ways for stock to change and only
 * one of them would be audited.
 *
 * Ported from shared/services/item_service.py. The sync outbox writes are
 * dropped per LARAVEL_PLAN.md §8; the per-batch SUM() loops are replaced with
 * aggregate queries, which is the only behavioural liberty taken and is covered
 * by query-count tests.
 */
class ItemService
{
    /**
     * Fields an edit may touch.
     *
     * item_code is deliberately absent: it is the human key, and it may already
     * be printed on a receipt or a shelf label. Changing it would orphan both.
     */
    public const EDITABLE_FIELDS = [
        'item_name', 'manufacturer', 'category', 'description',
        'sales_price', 'purchase_price', 'unit_of_measure', 'location',
        'reorder_level', 'is_narcotic', 'is_active', 'barcode',
        'pack_size', 'retail_price', 'max_discount_percent',
    ];

    public function __construct(private readonly StockService $stock) {}

    // -----------------------------------------------------------------------
    // catalogue
    // -----------------------------------------------------------------------

    /**
     * Add a medicine to the catalogue.
     *
     * @throws ItemException on a blank code or name, a duplicate code, or a
     *                       barcode already assigned elsewhere
     */
    public function create(
        string $itemCode,
        string $itemName,
        string $deviceId,
        string|float|int $salesPrice = 0,
        string|float|int $purchasePrice = 0,
        ?string $manufacturer = null,
        ?string $category = null,
        ?string $unitOfMeasure = null,
        ?string $location = null,
        int $reorderLevel = 0,
        bool $isNarcotic = false,
        ?string $barcode = null,
        ?string $description = null,
        int $packSize = 1,
        string|float|int|null $retailPrice = null,
        string|float|int|null $maxDiscountPercent = null,
    ): Item {
        $itemCode = trim($itemCode);
        $itemName = trim($itemName);

        // An empty string is not a barcode; it is the absence of one. Stored as
        // null so the unique index does not treat two unbarcoded items as a
        // collision.
        $barcode = trim((string) $barcode) ?: null;

        if ($itemCode === '' || $itemName === '') {
            throw new ItemException('item code and name are required');
        }

        if (Item::where('item_code', $itemCode)->exists()) {
            throw new ItemException("item code '{$itemCode}' already exists");
        }

        if ($barcode !== null && $this->findByBarcode($barcode) !== null) {
            // A shared barcode makes a scan ambiguous, and the till would pick
            // one silently - charging for the wrong medicine.
            throw new ItemException("barcode '{$barcode}' is already assigned to another item");
        }

        if ($packSize < 1) {
            // A pack of zero pieces makes every per-piece price a division by
            // zero and every receipt of "n packs" add nothing to the shelf.
            throw new ItemException('pieces in a pack must be at least 1');
        }

        if ($maxDiscountPercent !== null && ((float) $maxDiscountPercent < 0 || (float) $maxDiscountPercent > 100)) {
            throw new ItemException('max discount must be between 0 and 100 per cent');
        }

        $item = Item::create([
            'item_code' => $itemCode,
            'item_name' => $itemName,
            'barcode' => $barcode,
            'manufacturer' => $manufacturer,
            'category' => $category,
            'description' => $description,
            'sales_price' => $salesPrice,
            'purchase_price' => $purchasePrice,
            'pack_size' => $packSize,
            'retail_price' => $retailPrice,
            'max_discount_percent' => $maxDiscountPercent,
            'unit_of_measure' => $unitOfMeasure,
            'location' => $location,
            'reorder_level' => $reorderLevel,
            'is_narcotic' => $isNarcotic,
            'origin_device_id' => $deviceId,
        ]);

        Log::info('item created', ['code' => $item->item_code, 'name' => $item->item_name]);

        return $item;
    }

    /**
     * Edit an item's mutable fields.
     *
     * updated_at is bumped by BaseModel when a hashed column changes, which is
     * what drives last-write-wins between two devices.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws ItemException on an unknown field, a blank name, or a duplicate barcode
     */
    public function update(Item $item, string $deviceId, array $changes): Item
    {
        foreach (array_keys($changes) as $field) {
            if (! in_array($field, self::EDITABLE_FIELDS, true)) {
                throw new ItemException("field '{$field}' is not editable");
            }
        }

        if (array_key_exists('item_name', $changes) && trim((string) $changes['item_name']) === '') {
            throw new ItemException('name cannot be blank');
        }

        if (array_key_exists('pack_size', $changes) && (int) $changes['pack_size'] < 1) {
            throw new ItemException('pieces in a pack must be at least 1');
        }

        if (array_key_exists('max_discount_percent', $changes) && $changes['max_discount_percent'] !== null) {
            $percent = (float) $changes['max_discount_percent'];

            if ($percent < 0 || $percent > 100) {
                throw new ItemException('max discount must be between 0 and 100 per cent');
            }
        }

        if (array_key_exists('barcode', $changes)) {
            $barcode = trim((string) $changes['barcode']) ?: null;

            if ($barcode !== null) {
                $existing = $this->findByBarcode($barcode);

                // Compared by uuid so re-submitting an unchanged edit form does
                // not trip the check against the item itself.
                if ($existing !== null && $existing->uuid !== $item->uuid) {
                    throw new ItemException("barcode '{$barcode}' is already assigned to another item");
                }
            }

            $changes['barcode'] = $barcode;
        }

        $item->fill($changes)->save();

        Log::info('item updated', ['code' => $item->item_code, 'fields' => array_keys($changes)]);

        return $item;
    }

    // -----------------------------------------------------------------------
    // receiving goods
    // -----------------------------------------------------------------------

    /**
     * Receive goods into a new batch: create the batch row, then let
     * StockService append the positive ledger row that actually moves the stock.
     *
     * @throws ItemException on a blank batch number, a missing expiry, a
     *                       non-positive quantity, or a duplicate batch number
     */
    public function receiveNewBatch(
        string $itemUuid,
        string $batchNumber,
        ?string $expiryDate,
        int $quantity,
        string|float|int $purchasePrice,
        string $deviceId,
        ?string $mfgDate = null,
        ?string $supplierUuid = null,
        ?string $performedByUserUuid = null,
    ): ItemBatch {
        $batchNumber = trim($batchNumber);

        if ($batchNumber === '') {
            throw new ItemException('batch number is required');
        }

        if ($expiryDate === null || trim($expiryDate) === '') {
            // Sales pick the batch expiring first, so a batch with no expiry
            // could never be ordered - and therefore could never be sold.
            throw new ItemException('expiry date is required');
        }

        if ($quantity <= 0) {
            throw new ItemException('received quantity must be positive');
        }

        $duplicate = ItemBatch::where('item_uuid', $itemUuid)
            ->where('batch_number', $batchNumber)
            ->exists();

        if ($duplicate) {
            // Two batches sharing a number cannot be told apart on a shelf or
            // during a recall. Uniqueness is per item: manufacturers reuse
            // batch numbers across different products.
            throw new ItemException("batch '{$batchNumber}' already exists for this item");
        }

        /*
         * The batch row and its opening ledger row are one transaction. A batch
         * with no ledger row would report stock of zero while sitting full on a
         * shelf, and would look like a bug in the derived-stock logic rather
         * than a half-finished write.
         */
        return DB::transaction(function () use (
            $itemUuid, $batchNumber, $expiryDate, $quantity, $purchasePrice,
            $deviceId, $mfgDate, $supplierUuid, $performedByUserUuid
        ) {
            $batch = ItemBatch::create([
                'item_uuid' => $itemUuid,
                'batch_number' => $batchNumber,
                'mfg_date' => $mfgDate,
                'expiry_date' => $expiryDate,
                'received_qty' => $quantity,
                'purchase_price' => $purchasePrice,
                'received_date' => BusinessDate::today(),
                'supplier_uuid' => $supplierUuid,
                'origin_device_id' => $deviceId,
            ]);

            $this->stock->receive(
                itemUuid: $itemUuid,
                batchUuid: $batch->uuid,
                quantity: $quantity,
                deviceId: $deviceId,
                performedByUserUuid: $performedByUserUuid,
            );

            Log::info('goods received', [
                'batch' => $batchNumber,
                'item' => $itemUuid,
                'quantity' => $quantity,
            ]);

            return $batch;
        });
    }

    // -----------------------------------------------------------------------
    // reads for the UI
    // -----------------------------------------------------------------------

    /** All catalogue items, name-sorted. */
    public function list(bool $activeOnly = true): EloquentCollection
    {
        return Item::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('item_name')
            ->get();
    }

    /**
     * POS search by code, name, or barcode.
     *
     * Two characters minimum: one character matches most of the catalogue, and
     * the POS would render the whole shop on the first keystroke.
     */
    public function search(string $term, int $limit = 25): EloquentCollection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return new EloquentCollection;
        }

        $like = '%'.$term.'%';

        return Item::query()
            ->where('is_active', true)
            ->where(function ($q) use ($like) {
                // whereLike with caseSensitive: false rather than ilike, which
                // is PostgreSQL-only and would break on both MySQL and SQLite.
                $q->whereLike('item_name', $like, caseSensitive: false)
                    ->orWhereLike('item_code', $like, caseSensitive: false)
                    ->orWhereLike('barcode', $like, caseSensitive: false);
            })
            ->orderBy('item_name')
            ->limit($limit)
            ->get();
    }

    /**
     * Exact barcode lookup - the scan-and-go path.
     *
     * A scanner types the whole barcode and sends Enter in one burst, so the POS
     * treats an exact match on Enter as "add to cart now".
     */
    public function findByBarcode(string $barcode): ?Item
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return null;
        }

        return Item::query()
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->first();
    }

    // -----------------------------------------------------------------------
    // FEFO - first expiry, first out
    // -----------------------------------------------------------------------

    /**
     * The batch to sell from: earliest expiry that still has stock.
     *
     * Selling the earliest-expiring stock first is how a pharmacy avoids writing
     * off inventory it already paid for. Picking by receipt order - the easy
     * mistake - leaves the shop sitting on stock until it expires.
     *
     * When nothing has stock the earliest batch is returned rather than null, so
     * an oversell is still attributed to a real batch. That path is reached in
     * normal trading, because negative stock is permitted: the shelf stops a
     * sale, not the database.
     *
     * Two queries regardless of how many batches the item has. The Python runs
     * one SUM() per batch, and this is on the POS hot path.
     */
    public function sellableBatch(string $itemUuid): ?ItemBatch
    {
        $batches = ItemBatch::query()
            ->where('item_uuid', $itemUuid)
            ->orderBy('expiry_date')
            ->get();

        if ($batches->isEmpty()) {
            return null;
        }

        $quantities = $this->stock->batchQuantitiesFor($batches->pluck('uuid')->all());

        foreach ($batches as $batch) {
            if (($quantities[$batch->uuid] ?? 0) > 0) {
                return $batch;
            }
        }

        return $batches->first();
    }

    // -----------------------------------------------------------------------
    // stock alerts - a diagnostic list, never a blocking workflow
    // -----------------------------------------------------------------------

    /**
     * Items at or below their reorder level, using derived quantity so the alert
     * can never disagree with the ledger.
     *
     * reorder_level 0 means "not tracked", not "reorder at zero" - otherwise
     * every item in the shop would sit permanently on the alert screen.
     *
     * @return Collection<int, array{item: Item, qty: int}>
     */
    public function lowStockItems(): Collection
    {
        $tracked = Item::query()
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->orderBy('item_name')
            ->get();

        if ($tracked->isEmpty()) {
            return collect();
        }

        $quantities = $this->stock->quantitiesFor($tracked->pluck('uuid')->all());

        return $tracked
            ->map(fn (Item $item) => ['item' => $item, 'qty' => $quantities[$item->uuid]])
            // At or below: an item sitting exactly AT its reorder level is the
            // moment to reorder, not one unit later.
            ->filter(fn (array $row) => $row['qty'] <= $row['item']->reorder_level)
            ->values();
    }

    /**
     * Batches holding stock that expire within $withinDays, soonest first.
     *
     * Already-expired batches are excluded here and reported by expiredBatches()
     * instead: one list is "clear these shelves soon", the other is "these
     * cannot be sold at all", and they are different jobs.
     *
     * @return Collection<int, array{batch: ItemBatch, item: Item}>
     */
    public function expiringBatches(int $withinDays = 30): Collection
    {
        $today = BusinessDate::for();

        return $this->batchesWithStock(
            fn ($query) => $query->whereBetween('expiry_date', [
                $today->toDateString(),
                $today->addDays($withinDays)->toDateString(),
            ])
        );
    }

    /**
     * Batches that still hold stock but whose expiry has passed, soonest first.
     *
     * @return Collection<int, array{batch: ItemBatch, item: Item}>
     */
    public function expiredBatches(): Collection
    {
        return $this->batchesWithStock(
            fn ($query) => $query->where('expiry_date', '<', BusinessDate::for()->toDateString())
        );
    }

    /**
     * Batches matching $filter that still hold stock, paired with their item.
     *
     * Three queries: the candidate batches, one aggregate for their quantities,
     * and one for the items. The Python equivalent runs a SUM() per batch across
     * the whole catalogue, which is the slowest thing on the alerts screen.
     *
     * An empty batch is filtered out because it cannot be pulled off a shelf.
     * Warning about it is noise, and noise is why staff stop reading alerts.
     *
     * @return Collection<int, array{batch: ItemBatch, item: Item}>
     */
    private function batchesWithStock(callable $filter): Collection
    {
        $batches = ItemBatch::query()
            ->tap($filter)
            ->orderBy('expiry_date')
            ->get();

        if ($batches->isEmpty()) {
            return collect();
        }

        $quantities = $this->stock->batchQuantitiesFor($batches->pluck('uuid')->all());

        $withStock = $batches->filter(fn (ItemBatch $b) => ($quantities[$b->uuid] ?? 0) > 0)->values();

        if ($withStock->isEmpty()) {
            return collect();
        }

        // The screen shows the item name against every row; without this it
        // would be one lookup per row.
        $items = Item::query()
            ->whereIn('uuid', $withStock->pluck('item_uuid')->unique()->all())
            ->get()
            ->keyBy('uuid');

        return $withStock
            ->map(fn (ItemBatch $batch) => [
                'batch' => $batch,
                'item' => $items[$batch->item_uuid] ?? null,
            ])
            ->values();
    }
}
