<?php

namespace App\Http\Controllers;

use App\Models\PrintLog;
use App\Models\Sale;
use App\Models\Setting;
use App\Support\BusinessDate;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
 * Sales history and the receipt.
 *
 * Read-only throughout. A sale is never edited: a mistake is corrected by a
 * return, which is a new record with its own compensating ledger rows. There is
 * deliberately no edit action on this controller for anyone to find.
 */
class SaleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'in:cash,mobile'],
        ]);

        $query = Sale::query()
            ->with(['staffMember', 'customer'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->whereLike('invoice_number', "%{$term}%", caseSensitive: false)
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('phone_number', "%{$term}%"));
            }))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('sale_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('sale_date', '<=', $to))
            ->when($filters['payment_method'] ?? null, fn ($q, $m) => $q->where('payment_method', $m))
            ->latest('sale_time');

        /*
         * Paginated at 50, the same as the Python client. Not because the table
         * would be slow to render, but because an unbounded query over a year of
         * sales on the shop's 1 GB VPS is how a page times out.
         */
        $sales = $query->paginate(50)->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'filters' => $filters,

            // The total for the CURRENT FILTER, not the current page. Someone
            // filtering to a day wants that day's takings, and a per-page figure
            // would silently answer a different question.
            'filteredTotal' => Money::format($query->clone()->sum('net_amount')),
        ]);
    }

    public function show(Sale $sale): View
    {
        $sale->load(['lines.item', 'lines.batch', 'staffMember', 'customer', 'familyMember', 'returns.lines']);

        return view('sales.show', ['sale' => $sale]);
    }

    /**
     * The printable receipt.
     *
     * Each view is logged and the sale's print_count incremented. A reprint is a
     * legitimate act - a customer asks for a copy - but it is also how a sale
     * could be shown twice to two people, so the count is part of the fraud
     * trail. print_count is excluded from the record hash but IS synced, because
     * a reprint is a real event.
     */
    public function receipt(Request $request, Sale $sale): View
    {
        $sale->load(['lines.item', 'staffMember', 'customer']);

        // Incremented without touching updated_at: a reprint is not an edit to
        // the sale, and bumping it would change the record hash.
        $sale->print_count = $sale->print_count + 1;
        $sale->save();

        PrintLog::create([
            'document_type' => 'receipt',
            'reference_uuid' => $sale->uuid,
            'printed_by_user_uuid' => $request->user()->uuid,
            'session_uuid' => $sale->session_uuid,
            'print_count_at_time' => $sale->print_count,
            'printed_at' => BusinessDate::nowUtc(),
            'origin_device_id' => config('pharmacy.device_id'),
        ]);

        return view('sales.receipt', [
            'sale' => $sale,
            'settings' => Setting::query()->first(),
        ]);
    }
}
