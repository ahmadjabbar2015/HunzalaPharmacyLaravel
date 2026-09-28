<?php

namespace App\Services;

/*
 * One line of a walk-in delivery with no prior purchase order.
 *
 * Carries its own unit cost, because there is no PO line to inherit one from.
 */
readonly class DirectLine
{
    public function __construct(
        public string $itemUuid,
        public string $batchNumber,
        public string $expiryDate,
        public int $quantity,
        public string $unitCost,
        public ?string $mfgDate = null,
    ) {}
}
