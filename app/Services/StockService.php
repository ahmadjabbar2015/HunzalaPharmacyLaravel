<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\StockTransaction;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/*
 * THE FIRST SERVICE. Built and proved before any screen exists, because every
 * quantity in the system comes from here.
 *
 * It is the only place stock is written, and it enforces RULE 2: stock is
 * event-sourced. Every movement is an append-only row in stock_transactions.
 * The quantity is DERIVED with SUM(qty_change); items.current_stock_qty and
 * item_batches.current_qty are only a performance cache, recomputed locally.
 *
 * Why derive rather than store a counter: a stored count drifts. It drifts when
 * a write is interrupted, when two devices edit it, when a restore lands. And it
 * drifts silently - the number still looks like a number. A pharmacy discovering
 * six months later that its stock figures were wrong has no way back. A ledger
 * cannot drift: the rows either exist or they do not, and the total is a
 * function of them.
 *
 * Ported from shared/services/stock_service.py. The sync outbox that the Python
 * version writes alongside each row is deliberately absent - LARAVEL_PLAN.md §8
 * drops the sync layer for a web-only deployment. Everything else is faithful.
 */
class StockService
{
    /*
     * A positive qty_change is expected for these; negative for the rest. Held
     * as config rather than a database ENUM so every engine stores identical
     * text and the record hashes match.
     */
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_SALE = 'sale';

    public const TYPE_RETURN = 'return';

    public const TYPE_ADJUSTMENT = 'adjustment';

    // -----------------------------------------------------------------------
    // DERIVED QUANTITY - the source of truth (RULE 2)
    // -----------------------------------------------------------------------

    /**
     * Current stock for an item, summed from the ledger.
     *
     * This is authoritative. The cache column is not, and nothing in this class
     * ever reads it to answer a question.
     */
    public function quantityFor(string $itemUuid): int
    {
        return (int) StockTransaction::query()
            ->where('item_uuid', $itemUuid)
            ->sum('qty_change');
    }

    /** Current stock for one batch, summed from the ledger. */
    public function batchQuantityFor(string $batchUuid): int
    {
        return (int) StockTransaction::query()
            ->where('batch_uuid', $batchUuid)
            ->sum('qty_change');
    }

    /**
     * Quantities for many items in ONE query.
     *
     * LARAVEL_PLAN.md §5's second named trap. quantityFor() is a SUM() per item,
     * so an inventory list calling it per row issues one query per row - 500
     * queries to render 500 items, which is why the Python client paginates to
     * 50. Any screen showing more than one item's stock must use this instead.
     *
     * @param  list<string>  $itemUuids
     * @return array<string, int> every uuid asked for, defaulting to 0
     */
    public function quantitiesFor(array $itemUuids): array
    {
        if ($itemUuids === []) {
            return [];
        }

        $summed = StockTransaction::query()
            ->whereIn('item_uuid', $itemUuids)
            ->groupBy('item_uuid')
            ->selectRaw('item_uuid, COALESCE(SUM(qty_change), 0) as qty')
            ->pluck('qty', 'item_uuid');

        // Items with no ledger rows are absent from the result set. Fill them in
        // as 0 so callers never need a null check - one of them would forget and
        // render an empty cell where the answer is "0".
        $quantities = array_fill_keys($itemUuids, 0);

        foreach ($summed as $itemUuid => $qty) {
            $quantities[$itemUuid] = (int) $qty;
        }

        return $quantities;
    }

    /**
     * Quantities for many batches in one query. The FEFO batch picker needs
     * every candidate batch's quantity at once.
     *
     * @param  list<string>  $batchUuids
     * @return array<string, int>
     */
    public function batchQuantitiesFor(array $batchUuids): array
    {
        if ($batchUuids === []) {
            return [];
        }

        $summed = StockTransaction::query()
            ->whereIn('batch_uuid', $batchUuids)
            ->groupBy('batch_uuid')
            ->selectRaw('batch_uuid, COALESCE(SUM(qty_change), 0) as qty')
            ->pluck('qty', 'batch_uuid');

        $quantities = array_fill_keys($batchUuids, 0);

        foreach ($summed as $batchUuid => $qty) {
            $quantities[$batchUuid] = (int) $qty;
        }

        return $quantities;
    }

    // -----------------------------------------------------------------------
    // LEDGER WRITE - the only way stock changes
    // -----------------------------------------------------------------------

    /**
     * Append one movement to the ledger.
     *
     * @param  int  $qtyChange  signed; negative for a sale, positive for a
     *                          purchase or return. Prefer the wrappers below,
     *                          which own the sign so call sites cannot get it
     *                          backwards.
     * @param  string|null  $reason  REQUIRED when $transactionType is 'adjustment'
     *
     * @throws InvalidArgumentException on an unknown type, a zero change, or a
     *                                  missing adjustment reason
     */
    public function record(
        string $itemUuid,
        int $qtyChange,
        string $transactionType,
        string $deviceId,
        ?string $batchUuid = null,
        ?string $performedByUserUuid = null,
        ?string $sessionUuid = null,
        ?string $referenceUuid = null,
        ?string $referenceType = null,
        ?string $reason = null,
        ?string $transactionDate = null,
    ): StockTransaction {
        if (! in_array($transactionType, config('pharmacy.transaction_types'), true)) {
            throw new InvalidArgumentException("unknown transaction_type: {$transactionType}");
        }

        if ($qtyChange === 0) {
            // A zero movement is never a real event, so it is a bug in the
            // caller. Recording it would put a row in the ledger that means
            // nothing and can never be told apart from a mistake.
            throw new InvalidArgumentException('qty_change must not be zero');
        }

        if ($transactionType === self::TYPE_ADJUSTMENT && trim((string) $reason) === '') {
            // An adjustment is the one movement with no document behind it, so
            // the reason IS the document. It is also exactly where shrinkage
            // would be hidden, which is why this is enforced and not advisory.
            throw new InvalidArgumentException('a reason is required for a stock adjustment');
        }

        /*
         * The ledger row and the cache refresh share one transaction. A crash
         * between them would leave a cache that disagrees with the ledger - not
         * data loss, since the ledger is the truth, but it would show up on the
         * integrity screen as drift that no one caused.
         */
        return DB::transaction(function () use (
            $itemUuid, $qtyChange, $transactionType, $deviceId, $batchUuid,
            $performedByUserUuid, $sessionUuid, $referenceUuid, $referenceType,
            $reason, $transactionDate
        ) {
            $txn = StockTransaction::create([
                'item_uuid' => $itemUuid,
                'batch_uuid' => $batchUuid,
                'transaction_type' => $transactionType,
                'qty_change' => $qtyChange,
                'reference_uuid' => $referenceUuid,
                'reference_type' => $referenceType,
                'reason' => $reason,
                'performed_by_user_uuid' => $performedByUserUuid,
                'session_uuid' => $sessionUuid,
                'transaction_date' => $transactionDate ?? BusinessDate::nowUtc(),
                'origin_device_id' => $deviceId,
            ]);

            $derived = $this->recomputeCache($itemUuid);

            Log::info('ledger write', [
                'type' => $transactionType,
                'qty_change' => $qtyChange,
                'item' => $itemUuid,
                'batch' => $batchUuid,
                'derived' => $derived,
            ]);

            return $txn;
        });
    }

    // -----------------------------------------------------------------------
    // Convenience wrappers - readable call sites, correct signs enforced here
    // -----------------------------------------------------------------------

    /**
     * Goods received: a positive movement.
     *
     * The wrappers exist so no call site ever writes a sign. A screen passing
     * -5 to sell() because it "knew" stock goes down is how a sale becomes a
     * restock, and the ledger would record it as perfectly valid.
     */
    public function receive(
        string $itemUuid,
        string $batchUuid,
        int $quantity,
        string $deviceId,
        ?string $performedByUserUuid = null,
        ?string $sessionUuid = null,
        ?string $referenceUuid = null,
        ?string $referenceType = null,
        ?string $reason = null,
    ): StockTransaction {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('received quantity must be positive');
        }

        return $this->record(
            itemUuid: $itemUuid,
            qtyChange: $quantity,
            transactionType: self::TYPE_PURCHASE,
            deviceId: $deviceId,
            batchUuid: $batchUuid,
            performedByUserUuid: $performedByUserUuid,
            sessionUuid: $sessionUuid,
            referenceUuid: $referenceUuid,
            referenceType: $referenceType,
            reason: $reason,
        );
    }

    /** A sale. Pass a POSITIVE quantity; it is stored as a negative movement. */
    public function sell(
        string $itemUuid,
        int $quantity,
        string $deviceId,
        ?string $batchUuid = null,
        ?string $performedByUserUuid = null,
        ?string $sessionUuid = null,
        ?string $referenceUuid = null,
        ?string $referenceType = null,
    ): StockTransaction {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('sale quantity must be positive');
        }

        return $this->record(
            itemUuid: $itemUuid,
            qtyChange: -$quantity,
            transactionType: self::TYPE_SALE,
            deviceId: $deviceId,
            batchUuid: $batchUuid,
            performedByUserUuid: $performedByUserUuid,
            sessionUuid: $sessionUuid,
            referenceUuid: $referenceUuid,
            referenceType: $referenceType,
        );
    }

    /**
     * A customer return: a positive, compensating movement.
     *
     * Named returnToStock rather than return, because `return` is a PHP keyword
     * and cannot be a method name.
     */
    public function returnToStock(
        string $itemUuid,
        int $quantity,
        string $deviceId,
        ?string $batchUuid = null,
        ?string $performedByUserUuid = null,
        ?string $sessionUuid = null,
        ?string $referenceUuid = null,
        ?string $referenceType = null,
    ): StockTransaction {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('return quantity must be positive');
        }

        return $this->record(
            itemUuid: $itemUuid,
            qtyChange: $quantity,
            transactionType: self::TYPE_RETURN,
            deviceId: $deviceId,
            batchUuid: $batchUuid,
            performedByUserUuid: $performedByUserUuid,
            sessionUuid: $sessionUuid,
            referenceUuid: $referenceUuid,
            referenceType: $referenceType,
        );
    }

    /**
     * A manual correction. $qtyChange may be positive or negative, and $reason
     * is mandatory. Never edits the original row - this writes a new one.
     */
    public function adjust(
        string $itemUuid,
        int $qtyChange,
        string $reason,
        string $deviceId,
        ?string $batchUuid = null,
        ?string $performedByUserUuid = null,
        ?string $sessionUuid = null,
    ): StockTransaction {
        return $this->record(
            itemUuid: $itemUuid,
            qtyChange: $qtyChange,
            transactionType: self::TYPE_ADJUSTMENT,
            deviceId: $deviceId,
            batchUuid: $batchUuid,
            performedByUserUuid: $performedByUserUuid,
            sessionUuid: $sessionUuid,
            reason: $reason,
        );
    }

    // -----------------------------------------------------------------------
    // CACHE RECOMPUTE - a mirror of the ledger, never authoritative
    // -----------------------------------------------------------------------

    /**
     * Refresh items.current_stock_qty and every batch's current_qty from the
     * ledger. Returns the freshly derived item quantity.
     *
     * Writes with saveQuietly() and a direct attribute set so BaseModel's
     * updating hook sees only cache columns dirty and leaves updated_at alone.
     * If this bumped updated_at it would change the record hash, and every
     * sale would look to a reconciler like someone had edited the item.
     */
    public function recomputeCache(string $itemUuid): int
    {
        $derived = $this->quantityFor($itemUuid);

        $item = Item::find($itemUuid);

        if ($item !== null) {
            $item->current_stock_qty = $derived;
            $item->save();
        }

        $batches = ItemBatch::query()->where('item_uuid', $itemUuid)->get();

        if ($batches->isNotEmpty()) {
            // One query for every batch of this item rather than one per batch.
            $batchQuantities = $this->batchQuantitiesFor($batches->pluck('uuid')->all());

            foreach ($batches as $batch) {
                $batch->current_qty = $batchQuantities[$batch->uuid] ?? 0;
                $batch->save();
            }
        }

        return $derived;
    }

    /**
     * Rebuild every cache from the ledger. Returns the number of items touched.
     *
     * Used after a restore or when the integrity screen reports drift. Chunked
     * because a full catalogue will not fit comfortably in memory on the 1 GB
     * VPS this runs on.
     */
    public function recomputeAllCaches(): int
    {
        $count = 0;

        Item::query()->select('uuid')->chunkById(500, function ($items) use (&$count) {
            foreach ($items as $item) {
                $this->recomputeCache($item->uuid);
                $count++;
            }
        }, 'uuid');

        return $count;
    }

    // -----------------------------------------------------------------------
    // DIAGNOSTICS - surfaced, never auto-corrected
    // -----------------------------------------------------------------------

    /**
     * Items whose derived quantity is negative.
     *
     * Shown on Stock Alerts and never fixed automatically. Negative stock means
     * the ledger and the shelf disagree, and only a person looking at the shelf
     * can say which is right. Silently clamping it to zero would destroy the
     * evidence that something is wrong.
     *
     * @return list<array{item_uuid: string, qty: int}>
     */
    public function negativeStockItems(): array
    {
        return StockTransaction::query()
            ->groupBy('item_uuid')
            ->havingRaw('COALESCE(SUM(qty_change), 0) < 0')
            ->selectRaw('item_uuid, COALESCE(SUM(qty_change), 0) as qty')
            ->get()
            ->map(fn ($row) => ['item_uuid' => $row->item_uuid, 'qty' => (int) $row->qty])
            ->all();
    }

    /**
     * Items whose cached quantity disagrees with the ledger.
     *
     * This should always be empty. A row here means something wrote the column
     * without going through this service, or a recompute was interrupted - so it
     * is the integrity screen's main signal, and the reason recomputeAllCaches()
     * exists.
     *
     * @return list<array{item_uuid: string, cached: int, derived: int}>
     */
    public function cacheDrift(): array
    {
        $drift = [];

        Item::query()->select(['uuid', 'current_stock_qty'])->chunkById(500, function ($items) use (&$drift) {
            $derived = $this->quantitiesFor($items->pluck('uuid')->all());

            foreach ($items as $item) {
                if ((int) $item->current_stock_qty !== $derived[$item->uuid]) {
                    $drift[] = [
                        'item_uuid' => $item->uuid,
                        'cached' => (int) $item->current_stock_qty,
                        'derived' => $derived[$item->uuid],
                    ];
                }
            }
        }, 'uuid');

        return $drift;
    }
}
