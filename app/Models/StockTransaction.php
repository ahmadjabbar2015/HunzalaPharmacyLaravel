<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * THE LEDGER - the heart of the system (RULE 2).
 *
 * Append-only: a row here is never updated and never deleted. A correction is
 * a new compensating row, never an edit. Every quantity in the system is
 * SUM(qty_change) over these rows; two devices selling the same item offline
 * produce two independent rows and the sum is still right.
 */
class StockTransaction extends BaseModel
{
    protected $table = 'stock_transactions';

    protected function modelCasts(): array
    {
        return [
            'qty_change' => 'integer',
            'transaction_date' => 'datetime',
        ];
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
