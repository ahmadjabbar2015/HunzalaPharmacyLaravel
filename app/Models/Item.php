<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product in the catalogue.
 *
 * current_stock_qty is a CACHE. The real quantity is always
 * SUM(stock_transactions.qty_change) - see StockService.
 *
 * QUANTITIES ARE PIECES. pack_size says how many pieces come in a pack; it is a
 * multiplier used when goods are received and when a whole pack is sold, and it
 * never changes the unit the ledger counts in.
 *
 * PRICES: purchase_price and sales_price are per PACK. The per-piece price is
 * piecePrice() - retail_price when set, otherwise the pack price divided by
 * pack_size. Nothing outside this class should be doing that division.
 */
class Item extends BaseModel
{
    protected $table = 'items';

    protected $attributes = [
        'purchase_price' => 0,
        'sales_price' => 0,
        'pack_size' => 1,
        'current_stock_qty' => 0,
        'reorder_level' => 0,
        'is_narcotic' => false,
        'is_active' => true,
    ];

    protected function modelCasts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'sales_price' => 'decimal:2',
            'pack_size' => 'integer',
            'retail_price' => 'decimal:2',
            'max_discount_percent' => 'decimal:2',
            'current_stock_qty' => 'integer',
            'reorder_level' => 'integer',
            'is_narcotic' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * What one piece sells for - the price the till charges.
     *
     * retail_price wins when it is set, because a shop that sells a 8.33 tablet
     * at 10 has made a deliberate decision and the division must not overrule
     * it. Otherwise the pack price is split, rounded once.
     */
    public function piecePrice(): string
    {
        if ($this->retail_price !== null) {
            return Money::format($this->retail_price);
        }

        return Money::divide($this->sales_price, max(1, (int) $this->pack_size));
    }

    /** What one piece cost, for margin and stock valuation. */
    public function pieceCost(): string
    {
        return Money::divide($this->purchase_price, max(1, (int) $this->pack_size));
    }

    /**
     * What a whole pack sells for.
     *
     * When retail_price overrides the division, a pack is piece x pack_size
     * rather than sales_price - otherwise selling ten singles and selling one
     * box of ten would ring up different totals.
     */
    public function packPrice(): string
    {
        if ($this->retail_price !== null) {
            return Money::multiply($this->retail_price, max(1, (int) $this->pack_size));
        }

        return Money::format($this->sales_price);
    }

    /** True when this item is sold in packs at all. */
    protected function sellsInPacks(): Attribute
    {
        return Attribute::get(fn (): bool => (int) $this->pack_size > 1);
    }

    /** Pieces, rendered as "3 packs + 4" for someone standing at a shelf. */
    public function describePieces(int $pieces): string
    {
        $size = max(1, (int) $this->pack_size);
        $unit = $this->unit_of_measure ?: 'pc';

        if ($size === 1) {
            return "{$pieces} {$unit}";
        }

        $packs = intdiv($pieces, $size);
        $loose = $pieces % $size;

        // Pieces first: it is the figure the ledger holds and the one that has
        // to match a physical count.
        $parts = ["{$pieces} {$unit}"];
        $parts[] = $loose === 0 ? "({$packs} packs)" : "({$packs} packs + {$loose})";

        return implode(' ', $parts);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ItemBatch::class, 'item_uuid', 'uuid');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'item_uuid', 'uuid');
    }
}
