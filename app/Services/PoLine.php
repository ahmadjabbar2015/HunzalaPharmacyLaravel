<?php

namespace App\Services;

/** One line of a purchase order: what to buy, how many, at what cost. */
readonly class PoLine
{
    public function __construct(
        public string $itemUuid,
        public int $quantityOrdered,
        public string $unitCost,
    ) {}
}
