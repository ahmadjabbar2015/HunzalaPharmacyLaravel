<?php

namespace App\Models;

/**
 * Append-only accountability trail. Rows are never updated or deleted.
 * `details` holds compact, sorted-key JSON so the record hash is stable.
 */
class AuditLog extends BaseModel
{
    protected $table = 'audit_log';

    protected function modelCasts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
