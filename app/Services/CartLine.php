<?php

namespace App\Services;

use App\Support\Money;

/*
 * One line of a cart, on its way into a sale.
 *
 * A plain readonly object rather than an array: a cart is the one place in the
 * system where a typo in a key name would silently sell the wrong quantity at
 * the wrong price, and an array offers no protection against that.
 *
 * batchUuid is chosen by the CALLER, normally from ItemService::sellableBatch(),
 * so FEFO and batch valuation stay correct. This class does not pick a batch: a
 * cart line assembled in a test or an import must be able to name its own.
 *
 * Ported from sale_service.CartLine.
 */
readonly class CartLine
{
    public function __construct(
        public string $itemUuid,
        public int $quantity,
        public string $rate,
        public ?string $batchUuid = null,
    ) {}

    /**
     * The line total: rate x quantity, rounded once.
     *
     * Through Money rather than `*`, because $rate arrives as a string from
     * Eloquent's decimal cast and multiplying a string in PHP promotes it to
     * float - which is how a line total ends up a paisa off.
     */
    public function amount(): string
    {
        return Money::multiply($this->rate, $this->quantity);
    }
}
