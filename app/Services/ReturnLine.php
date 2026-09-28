<?php

namespace App\Services;

/*
 * One sale line coming back, on its way into a return.
 *
 * Identified by the SALE LINE, not by the item: the same medicine can appear
 * twice on one sale at different rates, and a refund has to match the rate the
 * customer actually paid on that line.
 *
 * Ported from return_service.ReturnLine.
 */
readonly class ReturnLine
{
    public function __construct(
        public string $saleItemUuid,
        public int $quantity,
    ) {}
}
