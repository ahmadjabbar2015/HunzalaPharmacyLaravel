<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Sale;
use App\Services\ItemService;
use App\Services\SessionService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\View\View;

/*
 * What someone needs to know on walking in.
 *
 * Deliberately a small number of figures. A dashboard that shows everything gets
 * read for a week and then skipped, and the things on it here are the ones that
 * cost money if missed: an unopened drawer, stock that has run out, stock about
 * to expire, and a ledger that disagrees with the shelf.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly StockService $stock,
        private readonly ItemService $items,
    ) {}

    public function __invoke(): View
    {
        $drawer = $this->sessions->openSession();
        $today = BusinessDate::for()->toDateString();

        $todaySales = Sale::query()->where('sale_date', $today);

        $negativeStock = collect($this->stock->negativeStockItems());

        // The names in one query rather than one per row: the banner lists items,
        // and a shop that has just had a bad delivery can easily show twenty.
        $negativeItems = Item::query()
            ->whereIn('uuid', $negativeStock->pluck('item_uuid')->all())
            ->get()
            ->keyBy('uuid');

        $expiryAlertDays = config('pharmacy.defaults.expiry_alert_days');

        return view('dashboard.index', [
            'drawer' => $drawer,

            // Only computed when a drawer is open; there is nothing to expect
            // from a closed one.
            'cashSales' => $drawer ? $this->sessions->cashSalesTotal($drawer->uuid) : Money::ZERO,
            'expectedCash' => $drawer ? $this->sessions->expectedCash($drawer) : Money::ZERO,

            'todayTakings' => Money::format($todaySales->clone()->sum('net_amount')),
            'todaySaleCount' => $todaySales->clone()->count(),

            'lowStockCount' => $this->items->lowStockItems()->count(),
            'expiringCount' => $this->items->expiringBatches($expiryAlertDays)->count(),
            'expiredCount' => $this->items->expiredBatches()->count(),
            'expiryAlertDays' => $expiryAlertDays,

            'negativeStock' => $negativeStock->map(fn (array $row) => [
                ...$row,
                'item' => $negativeItems[$row['item_uuid']] ?? null,
            ]),
        ]);
    }
}
