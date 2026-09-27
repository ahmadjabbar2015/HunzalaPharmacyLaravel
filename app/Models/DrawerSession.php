<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shop's shared cash drawer - one per evening, opened by whoever arrives
 * first and closed by whoever leaves last. Named DrawerSession, on table
 * `drawer_sessions`, so it is never confused with the framework's HTTP session
 * store - which owns the name `sessions` and is a completely unrelated thing.
 *
 * Dated by the business date it was OPENED on, so a session running past
 * midnight stays a single session.
 */
class DrawerSession extends BaseModel
{
    protected $table = 'drawer_sessions';

    protected $attributes = [
        'status' => 'open',
        'is_provisional' => false,
    ];

    protected function modelCasts(): array
    {
        return [
            'session_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'closing_cash_counted' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'is_provisional' => 'boolean',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_uuid', 'uuid');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_uuid', 'uuid');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'session_uuid', 'uuid');
    }

    public function presence(): HasMany
    {
        return $this->hasMany(StaffPresence::class, 'session_uuid', 'uuid');
    }
}
