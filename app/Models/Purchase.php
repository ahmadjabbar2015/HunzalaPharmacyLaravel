<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A goods receipt - this is what actually moves stock in. po_uuid is null for a
 * direct receipt, which is how the shop handles a delivery that arrived without
 * a purchase order.
 */
class Purchase extends BaseModel
{
    protected $table = 'purchases';

    protected $attributes = [
        'total_amount' => 0,
    ];

    protected function modelCasts(): array
    {
        return [
            'purchase_date' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_uuid', 'uuid');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_uuid', 'uuid');
    }
}
