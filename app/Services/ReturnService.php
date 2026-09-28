<?php

namespace App\Services;

use App\Exceptions\ReturnException;
use App\Models\Customer;
use App\Models\ReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Support\BusinessDate;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Processing a return.
 *
 * A return is NEVER an edit to the original sale. It is a new record with its
 * own lines and one POSITIVE ledger row per returned line, and the original
 * sale's amounts are left exactly as they were - only is_returned is flagged.
 * That is what keeps the original's record hash stable and the ledger
 * append-only: the history of what was sold must stay true even after some of it
 * comes back.
 *
 * Ported from shared/services/return_service.py, with one deliberate fix - see
 * alreadyReturned() below.
 */
class ReturnService
{
    public function __construct(private readonly StockService $stock) {}

    // -----------------------------------------------------------------------
    // finding the original sale
    // -----------------------------------------------------------------------

    /** Find a sale by the invoice number printed on the customer's receipt. */
    public function findSaleByInvoice(string $invoiceNumber): ?Sale
    {
        return Sale::query()->where('invoice_number', trim($invoiceNumber))->first();
    }

    /**
     * Recent sales for a phone number - the path when the receipt is lost, which
     * is most of the time.
     *
     * @return Collection<int, Sale>
     */
    public function findSalesByPhone(string $phone, int $limit = 20): Collection
    {
        $customer = Customer::query()->where('phone_number', trim($phone))->first();

        if ($customer === null) {
            return new Collection;
        }

        return Sale::query()
            ->where('customer_uuid', $customer->uuid)
            ->latest('sale_time')
            ->limit($limit)
            ->get();
    }

    /**
     * The sale's lines, with how many of each have already been returned and how
     * many remain returnable.
     *
     * The remaining figure is what the return form must show. Showing the
     * original quantity instead is how the same line gets refunded twice.
     *
     * @return SupportCollection<int, array{line: SaleItem, returned: int, returnable: int}>
     */
    public function returnableItems(string $saleUuid): SupportCollection
    {
        $lines = SaleItem::query()->where('sale_uuid', $saleUuid)->get();

        if ($lines->isEmpty()) {
            return new SupportCollection;
        }

        $returned = $this->alreadyReturned($lines->pluck('uuid')->all());

        return $lines->map(fn (SaleItem $line) => [
            'line' => $line,
            'returned' => $returned[$line->uuid] ?? 0,
            'returnable' => $line->quantity - ($returned[$line->uuid] ?? 0),
        ])->values();
    }

    /**
     * How many units of each sale line have already been returned.
     *
     * THE PYTHON DOES NOT DO THIS, and that is a bug rather than a decision.
     * shared/services/return_service.py caps each return against the ORIGINAL
     * line quantity only, so a line of 3 units can be returned 3 units at a
     * time, repeatedly - refunding more money than the customer ever paid and
     * inflating stock with goods that never came back. Nothing in
     * tests/test_return_service.py covers a second return against the same line,
     * which is why it survived.
     *
     * LARAVEL_PLAN.md §1 lists "returns capped at what was actually sold" among
     * the rules that were expensive to get right. This is that rule, actually
     * enforced.
     *
     * One query for any number of lines.
     *
     * @param  list<string>  $saleItemUuids
     * @return array<string, int>
     */
    public function alreadyReturned(array $saleItemUuids): array
    {
        if ($saleItemUuids === []) {
            return [];
        }

        $summed = ReturnItem::query()
            ->whereIn('sale_item_uuid', $saleItemUuids)
            ->groupBy('sale_item_uuid')
            ->selectRaw('sale_item_uuid, COALESCE(SUM(quantity), 0) as qty')
            ->pluck('qty', 'sale_item_uuid');

        $returned = array_fill_keys($saleItemUuids, 0);

        foreach ($summed as $saleItemUuid => $qty) {
            $returned[$saleItemUuid] = (int) $qty;
        }

        return $returned;
    }

    // -----------------------------------------------------------------------
    // creating the return
    // -----------------------------------------------------------------------

    /**
     * Write the return and its compensating stock movements atomically.
     *
     * @param  list<ReturnLine>  $lines  which sale lines come back, and how many
     *
     * @throws ReturnException if the sale is not found, the lines are empty, a
     *                         line is not on the sale, or the quantity exceeds
     *                         what remains returnable
     */
    public function create(
        string $originalSaleUuid,
        array $lines,
        string $staffMemberUuid,
        string $deviceId,
        ?string $sessionUuid = null,
        string $refundMethod = 'cash',
        ?string $paymentReference = null,
    ): SaleReturn {
        if ($lines === []) {
            throw new ReturnException('cannot create a return with no items');
        }

        $sale = Sale::find($originalSaleUuid);

        if ($sale === null) {
            throw new ReturnException("sale '{$originalSaleUuid}' not found");
        }

        $saleItems = SaleItem::query()
            ->where('sale_uuid', $originalSaleUuid)
            ->get()
            ->keyBy('uuid');

        $returned = $this->alreadyReturned($saleItems->keys()->all());

        /*
         * Everything is validated BEFORE anything is written. A return that
         * failed on its third line having already refunded the first two would
         * leave the customer part-refunded with no record saying so.
         *
         * @var list<array{line: SaleItem, quantity: int}> $validated
         */
        $validated = [];
        $requested = [];

        foreach ($lines as $line) {
            if ($line->quantity <= 0) {
                throw new ReturnException('return quantity must be positive');
            }

            $saleItem = $saleItems->get($line->saleItemUuid);

            if ($saleItem === null) {
                throw new ReturnException(
                    "sale_item '{$line->saleItemUuid}' is not on sale '{$originalSaleUuid}'"
                );
            }

            // Accumulated per line, so naming the same line twice in one return
            // cannot slip past the cap by being checked in isolation.
            $requested[$saleItem->uuid] = ($requested[$saleItem->uuid] ?? 0) + $line->quantity;

            $remaining = $saleItem->quantity - ($returned[$saleItem->uuid] ?? 0);

            if ($requested[$saleItem->uuid] > $remaining) {
                throw new ReturnException(sprintf(
                    'cannot return %d of item %s: %d sold, %d already returned, %d returnable',
                    $requested[$saleItem->uuid],
                    $saleItem->item_uuid,
                    $saleItem->quantity,
                    $returned[$saleItem->uuid] ?? 0,
                    $remaining,
                ));
            }

            $validated[] = ['line' => $saleItem, 'quantity' => $line->quantity];
        }

        return DB::transaction(function () use (
            $sale, $validated, $staffMemberUuid, $deviceId, $sessionUuid,
            $refundMethod, $paymentReference
        ) {
            $now = BusinessDate::nowUtc();
            $businessDate = BusinessDate::for($now)->toDateString();

            // The refund total uses the ORIGINAL sale rate, not today's price. A
            // price rise between sale and return must not hand the customer more
            // money than they paid, and a price cut must not short them.
            $total = Money::sum(array_map(
                fn (array $v) => Money::multiply($v['line']->rate, $v['quantity']),
                $validated,
            ));

            $return = SaleReturn::create([
                'return_number' => DocumentNumber::return($businessDate, $deviceId),
                'original_sale_uuid' => $sale->uuid,
                'customer_uuid' => $sale->customer_uuid,
                'family_member_uuid' => $sale->family_member_uuid,
                'return_date' => $businessDate,
                'return_time' => $now,
                'staff_member_uuid' => $staffMemberUuid,
                'session_uuid' => $sessionUuid,
                'total_amount' => $total,
                'refund_method' => $refundMethod,
                'payment_reference' => $paymentReference,
                'origin_device_id' => $deviceId,
            ]);

            foreach ($validated as $entry) {
                $saleItem = $entry['line'];
                $quantity = $entry['quantity'];

                ReturnItem::create([
                    'return_uuid' => $return->uuid,
                    'sale_item_uuid' => $saleItem->uuid,
                    'item_uuid' => $saleItem->item_uuid,
                    'batch_uuid' => $saleItem->batch_uuid,
                    'quantity' => $quantity,
                    'rate' => $saleItem->rate,
                    'amount' => Money::multiply($saleItem->rate, $quantity),
                    'origin_device_id' => $deviceId,
                ]);

                // A compensating POSITIVE ledger row, into the same batch the
                // goods left from - so the returned stock carries the right
                // expiry rather than joining an arbitrary lot.
                $this->stock->returnToStock(
                    itemUuid: $saleItem->item_uuid,
                    quantity: $quantity,
                    deviceId: $deviceId,
                    batchUuid: $saleItem->batch_uuid,
                    performedByUserUuid: $staffMemberUuid,
                    sessionUuid: $sessionUuid,
                    referenceUuid: $return->uuid,
                    referenceType: 'return',
                );
            }

            // A state change, not an amount edit. The original sale's figures
            // stay exactly as they were.
            $sale->is_returned = true;
            $sale->save();

            Log::info('return completed', [
                'return' => $return->return_number,
                'original' => $sale->invoice_number,
                'total' => $total,
                'lines' => count($validated),
            ]);

            return $return;
        });
    }
}
