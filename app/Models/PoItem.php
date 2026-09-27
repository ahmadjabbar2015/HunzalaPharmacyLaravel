<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a purchase order.
 *
 * quantity_received is real data, not a cache: it is hashed and synced, and
 * it is what over-receipt is checked against.
 */
class PoItem extends BaseModel
{
    protected $table = 'po_items';

    protected $attributes = [
        'quantity_received' => 0,
    ];

    protected function modelCasts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_uuid', 'uuid');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_uuid', 'uuid');
    }

    public function outstandingQuantity(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
