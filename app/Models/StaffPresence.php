<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who was on the floor during a session. Staff arrive and leave at any time, so
 * this answers "who was here when this happened" without being a timeclock.
 *
 * Informational only: a failure here must never block a sale or a drawer close.
 */
class StaffPresence extends BaseModel
{
    protected $table = 'staff_presence';

    protected function modelCasts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(DrawerSession::class, 'session_uuid', 'uuid');
    }
}
