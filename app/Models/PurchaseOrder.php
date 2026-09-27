<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What was ordered from a supplier. A PO moves no stock on its own - stock
 * moves only when goods are received against it.
 */
class PurchaseOrder extends BaseModel
{
    protected $table = 'purchase_orders';

    protected $attributes = [
        'status' => 'ordered',
    ];

    protected function modelCasts(): array
    {
        return [
            'order_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PoItem::class, 'po_uuid', 'uuid');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Purchase::class, 'po_uuid', 'uuid');
    }
}
