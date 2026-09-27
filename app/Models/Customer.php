<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per phone number. The phone is the lookup key at the counter and is
 * immutable after creation. "WALK-IN" is a sentinel customer for anonymous sales.
 */
class Customer extends BaseModel
{
    public const WALK_IN_PHONE = 'WALK-IN';

    protected $table = 'customers';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function modelCasts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'customer_uuid', 'uuid');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'customer_uuid', 'uuid');
    }
}
