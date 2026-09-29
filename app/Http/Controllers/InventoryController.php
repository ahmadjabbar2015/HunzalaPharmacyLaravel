<?php

namespace App\Http\Controllers;

use App\Exceptions\ItemException;
use App\Models\Item;
use App\Models\Setting;
use App\Models\StockTransaction;
use App\Services\ItemService;
use App\Services\StockService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
 * The catalogue, receiving goods, and correcting stock.
 *
 * Every quantity on these screens is DERIVED - summed from the ledger, never read
 * from items.current_stock_qty. The cache column exists for speed and is only
 * ever refreshed by StockService; a screen that read it could show a figure the
 * ledger disagrees with, which is the failure this whole design exists to
 * prevent.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly ItemService $items,
        private readonly StockService $stock,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'show' => ['nullable', 'in:all,low,out,inactive'],
        ]);

        $items = Item::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->whereLike('item_name', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('item_code', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('barcode', "%{$term}%", caseSensitive: false);
            }))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(
                ($filters['show'] ?? 'all') !== 'inactive',
                fn ($q) => $q->where('is_active', true),
                fn ($q) => $q->where('is_active', false),
            )
            ->orderBy('item_name')
            // 50 a page, as the Python client does, because the derived-stock
            // aggregate below is cheap but the page is not free to render.
            ->paginate(50)
            ->withQueryString();

        // One query for the whole page's stock rather than one per row. §5's
        // second named trap, and the reason this is not just $item->quantity.
        $quantities = $this->stock->quantitiesFor($items->pluck('uuid')->all());

        /*
         * Filtering on a derived value has to happen after the aggregate, so
         * "low" and "out" filter the current page rather than the query. A shop
         * with a catalogue big enough for that to mislead would need the filter
         * pushed into SQL as a subquery - noted here rather than pretended away.
         */
        $rows = $items->getCollection()
            ->map(fn (Item $item) => ['item' => $item, 'qty' => $quantities[$item->uuid] ?? 0])
            ->when(
                ($filters['show'] ?? null) === 'low',
                fn ($rows) => $rows->filter(fn ($r) => $r['item']->reorder_level > 0 && $r['qty'] <= $r['item']->reorder_level)
            )
            ->when(
                ($filters['show'] ?? null) === 'out',
                fn ($rows) => $rows->filter(fn ($r) => $r['qty'] <= 0)
            )
            ->values();

        return view('inventory.index', [
            'items' => $items,
            'rows' => $rows,
            'filters' => $filters,
            'categories' => Item::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function show(Item $item): View
    {
        $batches = $item->batches()->orderBy('expiry_date')->get();
        $batchQuantities = $this->stock->batchQuantitiesFor($batches->pluck('uuid')->all());

        return view('inventory.show', [
            'item' => $item,
            'derivedQty' => $this->stock->quantityFor($item->uuid),
            'batches' => $batches,
            'batchQuantities' => $batchQuantities,
            'sellableBatch' => $this->items->sellableBatch($item->uuid),

            /*
             * The ledger for this item, most recent first. This is the screen
             * that answers "why does it say that?" - which is the whole point of
             * keeping a ledger rather than a counter, so it is shown here rather
             * than buried in a report.
             */
            'ledger' => StockTransaction::query()
                ->where('item_uuid', $item->uuid)
                ->with('batch')
                ->latest('transaction_date')
                ->limit(50)
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('inventory.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateItem($request);

        try {
            $item = $this->items->create(
                itemCode: $validated['item_code'],
                itemName: $validated['item_name'],
                deviceId: config('pharmacy.device_id'),
                salesPrice: $validated['sales_price'],
                purchasePrice: $validated['purchase_price'],
                manufacturer: $validated['manufacturer'] ?? null,
                category: $validated['category'] ?? null,
                unitOfMeasure: $validated['unit_of_measure'] ?? null,
                location: $validated['location'] ?? null,
                reorderLevel: (int) ($validated['reorder_level'] ?? 0),
                isNarcotic: $request->boolean('is_narcotic'),
                barcode: $validated['barcode'] ?? null,
                description: $validated['description'] ?? null,
                packSize: (int) ($validated['pack_size'] ?? 1),
                retailPrice: $validated['retail_price'] ?? null,
                maxDiscountPercent: $validated['max_discount_percent'] ?? null,
            );
        } catch (ItemException $e) {
            return back()->withInput()->withErrors(['item_code' => $e->getMessage()]);
        }

        return redirect()->route('inventory.show', $item)
            ->with('status', "{$item->item_name} added. Receive stock against it to put it on the shelf.");
    }

    public function edit(Item $item): View
    {
        return view('inventory.edit', ['item' => $item]);
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $validated = $this->validateItem($request, $item);

        // item_code is not editable - it is the human key and may already be
        // printed on a receipt or a shelf label - so it never reaches the service.
        unset($validated['item_code']);

        $validated['is_narcotic'] = $request->boolean('is_narcotic');
        $validated['is_active'] = $request->boolean('is_active');

        try {
            $this->items->update($item, config('pharmacy.device_id'), $validated);
        } catch (ItemException $e) {
            return back()->withInput()->withErrors(['barcode' => $e->getMessage()]);
        }

        return redirect()->route('inventory.show', $item)->with('status', 'Item updated.');
    }

    // -----------------------------------------------------------------------
    // receiving goods
    // -----------------------------------------------------------------------

    public function receiveForm(Item $item): View
    {
        return view('inventory.receive', ['item' => $item]);
    }

    public function receive(Request $request, Item $item): RedirectResponse
    {
        $validated = $request->validate([
            'batch_number' => ['required', 'string', 'max:80'],
            // Required, and after today: FEFO orders by it, and a batch with no
            // expiry could never be ordered and so could never be sold.
            'expiry_date' => ['required', 'date'],
            'mfg_date' => ['nullable', 'date', 'before_or_equal:expiry_date'],
            'quantity' => ['required', 'integer', 'min:1'],
            // Whether the number above is packs or loose pieces. The invoice
            // counts packs and the shelf counts pieces, and entering one as the
            // other is the error these fields exist to prevent.
            //
            // Optional, defaulting to pieces: pieces is what the ledger holds,
            // so a request without the field records exactly what it says. The
            // safe direction to be wrong in - defaulting to packs would multiply
            // a plain number by the pack size behind the typist's back.
            'quantity_unit' => ['nullable', 'in:packs,pieces'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'price_unit' => ['nullable', 'in:packs,pieces'],
        ]);

        $packSize = max(1, (int) $item->pack_size);

        /*
         * Everything below this line is in PIECES. The conversion happens once,
         * here, because the ledger has exactly one unit and a batch received in
         * packs but recorded in pieces - or the reverse - is a stock figure that
         * is wrong by a factor of pack_size and looks perfectly plausible.
         */
        $pieces = ($validated['quantity_unit'] ?? 'pieces') === 'packs'
            ? $validated['quantity'] * $packSize
            : (int) $validated['quantity'];

        $piecePrice = ($validated['price_unit'] ?? 'pieces') === 'packs'
            ? Money::divide($validated['purchase_price'], $packSize)
            : Money::format($validated['purchase_price']);

        try {
            $this->items->receiveNewBatch(
                itemUuid: $item->uuid,
                batchNumber: $validated['batch_number'],
                expiryDate: $validated['expiry_date'],
                quantity: $pieces,
                purchasePrice: $piecePrice,
                deviceId: config('pharmacy.device_id'),
                mfgDate: $validated['mfg_date'] ?? null,
                performedByUserUuid: $request->user()->uuid,
            );
        } catch (ItemException $e) {
            return back()->withInput()->withErrors(['batch_number' => $e->getMessage()]);
        }

        return redirect()->route('inventory.show', $item)
            ->with('status', "{$item->describePieces($pieces)} received into batch {$validated['batch_number']}.");
    }

    // -----------------------------------------------------------------------
    // correcting stock
    // -----------------------------------------------------------------------

    public function adjustForm(Item $item): View
    {
        $batches = $item->batches()->orderBy('expiry_date')->get();

        return view('inventory.adjust', [
            'item' => $item,
            'derivedQty' => $this->stock->quantityFor($item->uuid),
            'batches' => $batches,
            'batchQuantities' => $this->stock->batchQuantitiesFor($batches->pluck('uuid')->all()),
        ]);
    }

    public function adjust(Request $request, Item $item): RedirectResponse
    {
        $validated = $request->validate([
            // Signed, and never zero: a zero movement records nothing.
            'qty_change' => ['required', 'integer', 'not_in:0'],
            // The reason IS the document. An adjustment is the only movement with
            // nothing behind it, and the place shrinkage would hide.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'batch_uuid' => ['nullable', 'string', 'exists:item_batches,uuid'],
        ]);

        $this->stock->adjust(
            itemUuid: $item->uuid,
            qtyChange: (int) $validated['qty_change'],
            reason: $validated['reason'],
            deviceId: config('pharmacy.device_id'),
            batchUuid: $validated['batch_uuid'] ?? null,
            performedByUserUuid: $request->user()->uuid,
        );

        return redirect()->route('inventory.show', $item)
            ->with('status', 'Adjustment recorded. The original movements are unchanged.');
    }

    // -----------------------------------------------------------------------
    // alerts
    // -----------------------------------------------------------------------

    /**
     * Low stock, expiring batches, expired batches, and negative stock.
     *
     * One screen rather than four, because they are all the same job: things
     * someone has to walk to a shelf and deal with.
     */
    public function alerts(): View
    {
        $expiryDays = Setting::query()->value('expiry_alert_days')
            ?? config('pharmacy.defaults.expiry_alert_days');

        $negative = collect($this->stock->negativeStockItems());

        return view('inventory.alerts', [
            'lowStock' => $this->items->lowStockItems(),
            'expiring' => $this->items->expiringBatches($expiryDays),
            'expired' => $this->items->expiredBatches(),
            'expiryDays' => $expiryDays,

            'negative' => $negative->map(fn (array $row) => [
                ...$row,
                'item' => Item::find($row['item_uuid']),
            ]),
        ]);
    }

    /**
     * Shared rules for the create and edit forms.
     *
     * @return array<string, mixed>
     */
    private function validateItem(Request $request, ?Item $item = null): array
    {
        $validated = $request->validate([
            'item_code' => [
                $item === null ? 'required' : 'nullable',
                'string', 'max:50',
            ],
            'item_name' => ['required', 'string', 'max:200'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'sales_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            // Pieces in a pack. Optional, defaulting to 1: a request without it
            // is describing an item sold whole, which is what every item in the
            // catalogue was before packs existed. At least 1 - a pack of nothing
            // makes every per-piece price a division by zero.
            'pack_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // Blank means "just divide the pack price", which is why this is
            // nullable rather than defaulted.
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            // Blank means no ceiling. 0 is a real, different answer: never
            // discount this one.
            'max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'unit_of_measure' => ['nullable', 'string', 'max:30'],
            'location' => ['nullable', 'string', 'max:60'],
        ]);

        /*
         * A blank pack size means one piece to a pack - an item sold whole,
         * which is what every item in the catalogue was before packs existed.
         *
         * Only when the field was actually submitted and left empty. A request
         * that does not mention pack_size at all leaves it alone, because
         * forcing 1 there would silently flatten a pack of 20 the moment anyone
         * saved a form that happened not to carry the field.
         */
        if (array_key_exists('pack_size', $validated) && blank($validated['pack_size'])) {
            $validated['pack_size'] = 1;
        }

        return $validated;
    }
}
