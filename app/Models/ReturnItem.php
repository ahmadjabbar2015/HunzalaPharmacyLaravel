<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a return. The rate is copied from the original sale line, so a
 * later price change cannot alter what a customer is refunded.
 */
class ReturnItem extends BaseModel
{
    protected $table = 'return_items';

    protected function modelCasts(): array
    {
        return [
            'quantity' => 'integer',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'return_uuid', 'uuid');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_uuid', 'uuid');
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
