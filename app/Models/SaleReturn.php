<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A refund against an earlier sale. The table is `returns`; the class is
 * SaleReturn because `return` is a reserved word in PHP.
 *
 * A return is a new record with its own positive ledger rows - it never edits
 * the original sale's amounts.
 */
class SaleReturn extends BaseModel
{
    protected $table = 'returns';

    protected $attributes = [
        'total_amount' => 0,
        'print_count' => 0,
    ];

    protected function modelCasts(): array
    {
        return [
            'return_date' => 'date',
            'return_time' => 'datetime',
            'total_amount' => 'decimal:2',
            'print_count' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_uuid', 'uuid');
    }

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_uuid', 'uuid');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_uuid', 'uuid');
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_member_uuid', 'uuid');
    }
}
