<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product in the catalogue.
 *
 * current_stock_qty is a CACHE. The real quantity is always
 * SUM(stock_transactions.qty_change) - see StockService.
 */
class Item extends BaseModel
{
    protected $table = 'items';

    protected $attributes = [
        'purchase_price' => 0,
        'sales_price' => 0,
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
            'current_stock_qty' => 'integer',
            'reorder_level' => 'integer',
            'is_narcotic' => 'boolean',
            'is_active' => 'boolean',
        ];
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
