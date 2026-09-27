<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid to a supplier. A negative amount is allowed, as that is how a
 * mistaken payment is corrected without deleting the original record.
 */
class SupplierPayment extends BaseModel
{
    protected $table = 'supplier_payments';

    protected function modelCasts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_uuid', 'uuid');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_uuid', 'uuid');
    }
}
