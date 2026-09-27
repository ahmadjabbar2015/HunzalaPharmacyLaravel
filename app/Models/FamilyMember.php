<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person under a customer's phone number, so medicine history is per-person
 * rather than per-household.
 *
 * The column is `relationship_type` on both clients. It was once `relationship`
 * on the Python side to dodge a clash with SQLAlchemy's own relationship();
 * Eloquent has no such clash, and one name in both places means neither client
 * needs an alias to read it.
 */
class FamilyMember extends BaseModel
{
    protected $table = 'family_members';

    protected function modelCasts(): array
    {
        return [
            'dob' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_uuid', 'uuid');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'family_member_uuid', 'uuid');
    }
}
