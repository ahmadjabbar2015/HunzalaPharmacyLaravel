<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Staff, manager, or owner. The PIN is the identity at the point of sale:
 * it is unique among active users, so entering it says who completed a sale.
 */
class User extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'users';

    protected $hidden = ['password_hash', 'pin_hash'];

    protected $attributes = [
        'role' => 'staff',
        'is_active' => true,
        'pin_failed_attempts' => 0,
    ];

    protected function modelCasts(): array
    {
        return [
            'is_active' => 'boolean',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash ?? '';
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isManagerOrOwner(): bool
    {
        return in_array($this->role, ['manager', 'owner'], true);
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null
            && $this->pin_locked_until->isFuture();
    }

    public function presence(): HasMany
    {
        return $this->hasMany(StaffPresence::class, 'user_uuid', 'uuid');
    }
}
