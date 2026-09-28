<?php

namespace App\Services;

/*
 * One line of a goods receipt against a purchase order.
 *
 * Keyed by the PO LINE, not the item: the same item can be ordered twice on one
 * order at different costs, and the receipt has to accumulate against the right
 * one.
 *
 * $unitCost is nullable and defaults to the PO line's cost. Suppliers do change
 * a price between order and delivery, and the shop owes what was invoiced.
 */
readonly class ReceiveLine
{
    public function __construct(
        public string $poItemUuid,
        public string $batchNumber,
        public string $expiryDate,
        public int $quantity,
        public ?string $unitCost = null,
        public ?string $mfgDate = null,
    ) {}
}
