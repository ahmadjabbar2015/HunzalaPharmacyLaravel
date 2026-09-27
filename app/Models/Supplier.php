<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A distributor the shop buys from. The outstanding balance is never stored -
 * SupplierService derives it as opening_balance + purchases - payments, so it
 * can never drift out of step with the underlying records.
 */
class Supplier extends BaseModel
{
    protected $table = 'suppliers';

    protected $attributes = [
        'opening_balance' => 0,
        'is_active' => true,
    ];

    protected function modelCasts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_uuid', 'uuid');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'supplier_uuid', 'uuid');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_uuid', 'uuid');
    }
}
