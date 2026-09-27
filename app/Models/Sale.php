<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completed sale. staff_member_uuid is whoever entered their PIN to save it,
 * which is not necessarily whoever is logged in.
 *
 * When part of a sale is returned, this row's amounts are NOT edited - only
 * is_returned is flagged, so its record hash stays stable and the ledger stays
 * append-only.
 */
class Sale extends BaseModel
{
    protected $table = 'sales';

    protected $attributes = [
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'net_amount' => 0,
        'discount_pin_verified' => false,
        'print_count' => 0,
        'receipt_pending_print' => false,
        'made_while_unsynced' => false,
        'is_returned' => false,
    ];

    protected function modelCasts(): array
    {
        return [
            'sale_date' => 'date',
            'sale_time' => 'datetime',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'discount_pin_verified' => 'boolean',
            'print_count' => 'integer',
            'receipt_pending_print' => 'boolean',
            'made_while_unsynced' => 'boolean',
            'is_returned' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_uuid', 'uuid');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_uuid', 'uuid');
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'family_member_uuid', 'uuid');
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_member_uuid', 'uuid');
    }

    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class, 'session_uuid', 'uuid');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'original_sale_uuid', 'uuid');
    }
}
