<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received lot of an item. Expiry is mandatory because sales pick the batch
 * expiring first (FEFO), and an expiry-less batch could not be ordered.
 *
 * current_qty is a CACHE, like Item::current_stock_qty.
 */
class ItemBatch extends BaseModel
{
    protected $table = 'item_batches';

    protected $attributes = [
        'received_qty' => 0,
        'current_qty' => 0,
        'purchase_price' => 0,
        'is_expired' => false,
    ];

    protected function modelCasts(): array
    {
        return [
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'received_date' => 'date',
            'received_qty' => 'integer',
            'current_qty' => 'integer',
            'purchase_price' => 'decimal:2',
            'is_expired' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_uuid', 'uuid');
    }
}
