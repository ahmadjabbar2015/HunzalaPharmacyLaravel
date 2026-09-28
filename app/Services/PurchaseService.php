<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\PoItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Support\BusinessDate;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Purchase orders and goods receipts.
 *
 * The important distinction: a purchase ORDER is a plan and moves no stock. A
 * RECEIPT moves stock, and always through ItemService/StockService so the batch
 * and its ledger row are created together. An order that quietly added stock
 * would leave the shop selling goods still on a supplier's van.
 *
 * Ported from shared/services/purchase_service.py.
 */
class PurchaseService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(private readonly ItemService $items) {}

    // -----------------------------------------------------------------------
    // purchase orders - a plan, no stock movement
    // -----------------------------------------------------------------------

    /**
     * Place an order with a supplier.
     *
     * @param  list<PoLine>  $lines
     *
     * @throws PurchaseException on no lines, a non-positive quantity, or a
     *                           negative unit cost
     */
    public function createOrder(
        string $supplierUuid,
        string $deviceId,
        array $lines,
        ?string $orderDate = null,
        ?string $notes = null,
    ): PurchaseOrder {
        if ($lines === []) {
            throw new PurchaseException('a purchase order needs at least one line');
        }

        foreach ($lines as $line) {
            if ($line->quantityOrdered <= 0) {
                throw new PurchaseException('ordered quantity must be positive');
            }

            // Zero is allowed - a free replacement or a sample consignment is a
            // real thing a supplier sends. Negative is not.
            if (Money::isNegative($line->unitCost)) {
                throw new PurchaseException('unit cost cannot be negative');
            }
        }

        return DB::transaction(function () use ($supplierUuid, $deviceId, $lines, $orderDate, $notes) {
            $orderDate ??= BusinessDate::today();

            $order = PurchaseOrder::create([
                'po_number' => DocumentNumber::purchaseOrder($orderDate, $deviceId),
                'supplier_uuid' => $supplierUuid,
                'order_date' => $orderDate,
                'status' => self::STATUS_ORDERED,
                'notes' => $notes,
                'origin_device_id' => $deviceId,
            ]);

            foreach ($lines as $line) {
                PoItem::create([
                    'po_uuid' => $order->uuid,
                    'item_uuid' => $line->itemUuid,
                    'quantity_ordered' => $line->quantityOrdered,
                    'unit_cost' => Money::format($line->unitCost),
                    'origin_device_id' => $deviceId,
                ]);
            }

            Log::info('purchase order created', [
                'po' => $order->po_number,
                'supplier' => $supplierUuid,
                'lines' => count($lines),
            ]);

            return $order;
        });
    }

    /**
     * Cancel an order that has not been fully received.
     *
     * @throws PurchaseException if the order is unknown or fully received
     */
    public function cancelOrder(string $orderUuid): PurchaseOrder
    {
        $order = PurchaseOrder::find($orderUuid);

        if ($order === null) {
            throw new PurchaseException("purchase order '{$orderUuid}' not found");
        }

        if ($order->status === self::STATUS_RECEIVED) {
            // The goods are on the shelves. Cancelling would deny an order the
            // shop has already taken delivery of and owes money for.
            throw new PurchaseException('a fully received purchase order cannot be cancelled');
        }

        $order->status = self::STATUS_CANCELLED;
        $order->save();

        return $order;
    }

    /** @return Collection<int, PurchaseOrder> */
    public function listOrders(?string $supplierUuid = null, ?string $status = null): Collection
    {
        return PurchaseOrder::query()
            ->when($supplierUuid !== null, fn ($q) => $q->where('supplier_uuid', $supplierUuid))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('order_date')
            ->get();
    }

    public function findOrder(string $orderUuid): ?PurchaseOrder
    {
        return PurchaseOrder::find($orderUuid);
    }

    /** @return Collection<int, PoItem> */
    public function orderLines(string $orderUuid): Collection
    {
        return PoItem::query()->where('po_uuid', $orderUuid)->get();
    }

    // -----------------------------------------------------------------------
    // goods receipts - these move stock
    // -----------------------------------------------------------------------

    /**
     * Record goods received against a purchase order.
     *
     * Each line creates a batch and its positive ledger row, accumulates onto
     * the PO line, and the order's status is recomputed from what has actually
     * been received - never set by hand.
     *
     * @param  list<ReceiveLine>  $lines
     *
     * @throws PurchaseException on an unknown order or line, a cancelled order,
     *                           or a line that would over-receive
     */
    public function receiveAgainstOrder(
        string $orderUuid,
        string $deviceId,
        array $lines,
        ?string $invoiceReference = null,
        ?string $purchaseDate = null,
        ?string $performedByUserUuid = null,
    ): Purchase {
        $order = PurchaseOrder::find($orderUuid);

        if ($order === null) {
            throw new PurchaseException("purchase order '{$orderUuid}' not found");
        }

        if ($order->status === self::STATUS_CANCELLED) {
            throw new PurchaseException('cannot receive against a cancelled purchase order');
        }

        if ($lines === []) {
            throw new PurchaseException('nothing to receive');
        }

        $poItems = PoItem::query()->where('po_uuid', $orderUuid)->get()->keyBy('uuid');

        /*
         * Validated in full before anything is written, and requested quantities
         * accumulated per line - otherwise two lines naming the same PO line
         * would each pass the remaining-quantity check in isolation and together
         * over-receive.
         */
        $requested = [];

        foreach ($lines as $line) {
            $poItem = $poItems->get($line->poItemUuid);

            if ($poItem === null) {
                throw new PurchaseException("PO line '{$line->poItemUuid}' is not on this order");
            }

            if ($line->quantity <= 0) {
                throw new PurchaseException('received quantity must be positive');
            }

            $requested[$poItem->uuid] = ($requested[$poItem->uuid] ?? 0) + $line->quantity;
            $remaining = $poItem->quantity_ordered - $poItem->quantity_received;

            if ($requested[$poItem->uuid] > $remaining) {
                throw new PurchaseException(
                    "receiving {$requested[$poItem->uuid]} exceeds the {$remaining} still on order"
                );
            }
        }

        return DB::transaction(function () use (
            $order, $deviceId, $lines, $poItems, $invoiceReference, $purchaseDate, $performedByUserUuid
        ) {
            $purchaseDate ??= BusinessDate::today();

            $purchase = Purchase::create([
                'purchase_number' => DocumentNumber::goodsReceipt($purchaseDate, $deviceId),
                'supplier_uuid' => $order->supplier_uuid,
                'po_uuid' => $order->uuid,
                'purchase_date' => $purchaseDate,
                'total_amount' => Money::ZERO,
                'invoice_reference' => $invoiceReference,
                'origin_device_id' => $deviceId,
            ]);

            $amounts = [];

            foreach ($lines as $line) {
                $poItem = $poItems->get($line->poItemUuid);

                // The PO line's cost unless the delivery note says otherwise:
                // suppliers do change a price between order and delivery, and
                // the shop owes what was invoiced, not what was quoted.
                $unitCost = $line->unitCost ?? $poItem->unit_cost;

                $amounts[] = $this->receiveOneLine(
                    purchaseUuid: $purchase->uuid,
                    poItemUuid: $poItem->uuid,
                    itemUuid: $poItem->item_uuid,
                    supplierUuid: $order->supplier_uuid,
                    batchNumber: $line->batchNumber,
                    expiryDate: $line->expiryDate,
                    mfgDate: $line->mfgDate,
                    quantity: $line->quantity,
                    unitCost: $unitCost,
                    deviceId: $deviceId,
                    performedByUserUuid: $performedByUserUuid,
                );

                $poItem->quantity_received += $line->quantity;
                $poItem->save();
            }

            $purchase->total_amount = Money::sum($amounts);
            $purchase->save();

            $order->status = $this->statusAfterReceipt($order);
            $order->save();

            Log::info('goods received against order', [
                'receipt' => $purchase->purchase_number,
                'po' => $order->po_number,
                'total' => $purchase->total_amount,
                'status' => $order->status,
            ]);

            return $purchase;
        });
    }

    /**
     * Record goods received with no prior order - a walk-in delivery.
     *
     * Same stock and batch mechanics; there is simply nothing to reconcile
     * against a PO line.
     *
     * @param  list<DirectLine>  $lines
     *
     * @throws PurchaseException on no lines or a non-positive quantity
     */
    public function receiveDirect(
        string $supplierUuid,
        string $deviceId,
        array $lines,
        ?string $invoiceReference = null,
        ?string $purchaseDate = null,
        ?string $performedByUserUuid = null,
    ): Purchase {
        if ($lines === []) {
            throw new PurchaseException('nothing to receive');
        }

        foreach ($lines as $line) {
            if ($line->quantity <= 0) {
                throw new PurchaseException('received quantity must be positive');
            }
        }

        return DB::transaction(function () use (
            $supplierUuid, $deviceId, $lines, $invoiceReference, $purchaseDate, $performedByUserUuid
        ) {
            $purchaseDate ??= BusinessDate::today();

            $purchase = Purchase::create([
                'purchase_number' => DocumentNumber::goodsReceipt($purchaseDate, $deviceId),
                'supplier_uuid' => $supplierUuid,
                'po_uuid' => null,
                'purchase_date' => $purchaseDate,
                'total_amount' => Money::ZERO,
                'invoice_reference' => $invoiceReference,
                'origin_device_id' => $deviceId,
            ]);

            $amounts = [];

            foreach ($lines as $line) {
                $amounts[] = $this->receiveOneLine(
                    purchaseUuid: $purchase->uuid,
                    poItemUuid: null,
                    itemUuid: $line->itemUuid,
                    supplierUuid: $supplierUuid,
                    batchNumber: $line->batchNumber,
                    expiryDate: $line->expiryDate,
                    mfgDate: $line->mfgDate,
                    quantity: $line->quantity,
                    unitCost: $line->unitCost,
                    deviceId: $deviceId,
                    performedByUserUuid: $performedByUserUuid,
                );
            }

            $purchase->total_amount = Money::sum($amounts);
            $purchase->save();

            Log::info('direct receipt', [
                'receipt' => $purchase->purchase_number,
                'supplier' => $supplierUuid,
                'total' => $purchase->total_amount,
            ]);

            return $purchase;
        });
    }

    /** @return Collection<int, Purchase> */
    public function listPurchases(?string $supplierUuid = null): Collection
    {
        return Purchase::query()
            ->when($supplierUuid !== null, fn ($q) => $q->where('supplier_uuid', $supplierUuid))
            ->orderByDesc('purchase_date')
            ->get();
    }

    /** @return Collection<int, PurchaseItem> */
    public function purchaseLines(string $purchaseUuid): Collection
    {
        return PurchaseItem::query()->where('purchase_uuid', $purchaseUuid)->get();
    }

    // -----------------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------------

    /**
     * Create the batch and its ledger row, then the PurchaseItem tying them to
     * this receipt. Returns the line amount.
     *
     * The batch goes through ItemService so a goods receipt cannot bypass the
     * duplicate-batch and expiry checks that a manual receipt is held to.
     */
    private function receiveOneLine(
        ?string $poItemUuid,
        string $purchaseUuid,
        string $itemUuid,
        string $supplierUuid,
        string $batchNumber,
        string $expiryDate,
        ?string $mfgDate,
        int $quantity,
        string|int|float $unitCost,
        string $deviceId,
        ?string $performedByUserUuid,
    ): string {
        $batch = $this->items->receiveNewBatch(
            itemUuid: $itemUuid,
            batchNumber: $batchNumber,
            expiryDate: $expiryDate,
            quantity: $quantity,
            purchasePrice: $unitCost,
            deviceId: $deviceId,
            mfgDate: $mfgDate,
            supplierUuid: $supplierUuid,
            performedByUserUuid: $performedByUserUuid,
        );

        $amount = Money::multiply($unitCost, $quantity);

        PurchaseItem::create([
            'purchase_uuid' => $purchaseUuid,
            'po_item_uuid' => $poItemUuid,
            'item_uuid' => $itemUuid,
            'batch_uuid' => $batch->uuid,
            'quantity' => $quantity,
            'unit_cost' => Money::format($unitCost),
            'amount' => $amount,
            'origin_device_id' => $deviceId,
        ]);

        return $amount;
    }

    /**
     * The order's status, derived from what has actually been received.
     *
     * Derived rather than set, so a status can never claim a delivery that did
     * not arrive. Receiving MORE than ordered still counts as received - the
     * over-receipt is blocked earlier, so reaching here means the numbers add up.
     */
    private function statusAfterReceipt(PurchaseOrder $order): string
    {
        $lines = $this->orderLines($order->uuid);

        if ($lines->isEmpty()) {
            return $order->status;
        }

        if ($lines->every(fn (PoItem $l) => $l->quantity_received >= $l->quantity_ordered)) {
            return self::STATUS_RECEIVED;
        }

        if ($lines->contains(fn (PoItem $l) => $l->quantity_received > 0)) {
            return self::STATUS_PARTIALLY_RECEIVED;
        }

        return $order->status;
    }
}
