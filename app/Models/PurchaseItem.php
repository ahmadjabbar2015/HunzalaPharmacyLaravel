<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line on a goods receipt. Every line lands in a batch, so expiry is tracked. */
class PurchaseItem extends BaseModel
{
    protected $table = 'purchase_items';

    protected function modelCasts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_uuid', 'uuid');
    }

    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PoItem::class, 'po_item_uuid', 'uuid');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_uuid', 'uuid');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ItemBatch::class, 'batch_uuid', 'uuid');
    }
}
