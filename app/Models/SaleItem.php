<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line on a sale. The batch it drew from is recorded so COGS can be traced. */
class SaleItem extends BaseModel
{
    protected $table = 'sale_items';

    protected function modelCasts(): array
    {
        return [
            'quantity' => 'integer',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_uuid', 'uuid');
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
