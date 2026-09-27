<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\BusinessDate;

/*
 * The append-only accountability trail.
 *
 * Every sensitive action lands here as one immutable row: a PIN lockout, a
 * drawer open or close, a discount override, a stock adjustment. Rows are never
 * updated and never deleted - an audit trail that can be edited is not one.
 *
 * Ported from shared/services/audit_service.py.
 */
class AuditService
{
    /**
     * Append one audit row.
     *
     * @param  array<string, mixed>|null  $details  context (old/new values, counts)
     */
    public function record(
        string $action,
        string $deviceId,
        ?string $userUuid = null,
        ?string $sessionUuid = null,
        ?string $entityType = null,
        ?string $entityUuid = null,
        ?array $details = null,
    ): AuditLog {
        return AuditLog::create([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'user_uuid' => $userUuid,
            'session_uuid' => $sessionUuid,
            'details' => $details === null ? null : $this->encode($details),
            'occurred_at' => BusinessDate::nowUtc(),
            'origin_device_id' => $deviceId,
        ]);
    }

    /**
     * Compact, sorted-key JSON.
     *
     * Sorted because the record hash is computed over the stored text: the same
     * details written in a different key order would hash differently and look
     * to a reconciler like a row that had been tampered with. Compact separators
     * for the same reason - whitespace would be part of the hash.
     *
     * @param  array<string, mixed>  $details
     */
    private function encode(array $details): string
    {
        ksort($details);

        return json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
