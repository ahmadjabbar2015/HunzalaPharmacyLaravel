<?php

namespace App\Models;

use App\Models\Scopes\NotDeletedScope;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
 * Single source of truth for every syncable table. Implements three of the four
 * non-negotiable design rules:
 *
 *   RULE 1 - UUID primary keys, client-generated. Auto-increment is never the
 *            sync identity.
 *   RULE 2 - stock is event-sourced. Derived caches (current_stock_qty,
 *            current_qty) are never hashed and never pushed.
 *   RULE 4 - soft deletes only. Deletion is a state that syncs, never a missing
 *            row. This is why Laravel's SoftDeletes trait is NOT used: it keys
 *            off deleted_at alone and cannot express "deleted" as synced data.
 *
 * Ported from shared/models/base.py.
 */
abstract class BaseModel extends Model
{
    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    // Timestamps are managed by hand. Eloquent's automatic updated_at fires on
    // ANY write including cache-only ones, which would silently change the
    // record hash - see touchIfGenuinelyEdited() below.
    public $timestamps = false;

    // Writes go through the services, which decide what may be set.
    protected $guarded = [];

    /**
     * Every model declares defaults matching its migration's, and this merges
     * the sync defaults in on top.
     *
     * Without them a just-created row and the same row read back would hash
     * differently: columns that only the database defaults are absent from the
     * in-memory instance and canonicalise to null, so the hash would silently
     * change the first time a record was reloaded.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge(
            ['is_deleted' => false, 'is_synced' => false],
            $this->attributes,
        );

        parent::__construct($attributes);
    }

    /** The sync column casts every table shares, merged with the model's own. */
    protected function casts(): array
    {
        return array_merge([
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'is_deleted' => 'boolean',
            'is_synced' => 'boolean',
        ], $this->modelCasts());
    }

    protected function modelCasts(): array
    {
        return [];
    }

    /*
     * Columns excluded from the record hash: derived caches (RULE 2) and
     * per-device bookkeeping. Hashing these would make a healthy record look
     * different across devices and trigger phantom reconciliation mismatches.
     *
     * pin_failed_attempts / pin_locked_until are volatile, per-device anti-fraud
     * counters. A wrong PIN must not change the user's identity hash - otherwise
     * every mistyped PIN would look like an edit and drift the row.
     */
    public const HASH_EXCLUDED = [
        'is_synced',
        'current_stock_qty',
        'current_qty',
        'print_count',
        'pin_failed_attempts',
        'pin_locked_until',
    ];

    /*
     * Columns never sent over the wire. print_count is kept out of the hash but
     * IS carried in sync payloads, because a reprint on one device is a real
     * event the server should see. The PIN lockout counters stay local: a lock
     * is a device's own fraud brake, not shared state.
     */
    public const SYNC_EXCLUDED = [
        'is_synced',
        'current_stock_qty',
        'current_qty',
        'pin_failed_attempts',
        'pin_locked_until',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new NotDeletedScope);

        static::creating(function (self $model) {
            $now = BusinessDate::nowUtc();

            $model->uuid ??= (string) Str::uuid();                          // RULE 1
            $model->origin_device_id ??= config('pharmacy.device_id');
            $model->created_at ??= $now;
            $model->updated_at ??= $now;
            $model->is_deleted ??= false;
            $model->is_synced ??= false;
        });

        static::updating(function (self $model) {
            $model->touchIfGenuinelyEdited();
        });
    }

    /**
     * Bump updated_at on genuine edits, and only genuine edits.
     *
     * updated_at is part of the record hash and drives last-write-wins, so it
     * must move only when hashed data changes - never when a derived cache like
     * current_stock_qty is refreshed on the same row. A cache-only write or a
     * local flag flip changes nothing hashed, so the hash stays put. This makes
     * phantom reconciliation mismatches structurally impossible rather than
     * something a developer has to remember.
     */
    public function touchIfGenuinelyEdited(): void
    {
        $nonTrigger = array_merge(self::HASH_EXCLUDED, ['updated_at']);

        foreach (array_keys($this->getDirty()) as $column) {
            if (! in_array($column, $nonTrigger, true)) {
                $this->updated_at = BusinessDate::nowUtc();

                return;
            }
        }
    }

    /** RULE 4 - flag as deleted instead of removing the row. */
    public function softDelete(?string $userUuid = null): bool
    {
        $this->is_deleted = true;
        $this->deleted_at = BusinessDate::nowUtc();
        $this->deleted_by_user_uuid = $userUuid;

        return $this->save();
    }

    /** Include soft-deleted rows in a query. */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotDeletedScope::class);
    }

    /** Only soft-deleted rows. */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope(NotDeletedScope::class)->where('is_deleted', true);
    }

    /**
     * The payload pushed to the server for this record. Excludes the local-only
     * sync flag and the derived caches (RULE 2).
     */
    public function toSyncArray(): array
    {
        $payload = [];

        foreach ($this->syncableColumns() as $column) {
            if (in_array($column, self::SYNC_EXCLUDED, true)) {
                continue;
            }

            $value = $this->getAttribute($column);
            $payload[$column] = is_bool($value) ? $value : $this->canonicalValue($column, $value);
        }

        return $payload;
    }

    /**
     * Deterministic SHA-256 fingerprint of this record, identical across engines
     * for the same logical row. Excludes derived caches and local bookkeeping;
     * columns are sorted for stability.
     */
    public function recordHash(): string
    {
        $payload = [];

        foreach ($this->syncableColumns() as $column) {
            if (in_array($column, self::HASH_EXCLUDED, true)) {
                continue;
            }

            $payload[$column] = $this->canonicalValue($column, $this->getAttribute($column));
        }

        ksort($payload);

        // JSON_UNESCAPED_SLASHES matches Python's json.dumps, which does not
        // escape "/" - without it the two implementations hash differently.
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Normalise one value into a form that is byte-stable across engines. The
     * hash must not see engine-level surface differences: SQLite 0/1 vs MySQL
     * TINYINT, or 10.5 vs 10.50.
     *
     * A naive datetime is assumed to already be UTC, which is how we store it.
     */
    protected function canonicalValue(string $column, mixed $value): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        $cast = $this->getCasts()[$column] ?? null;

        if (is_string($cast) && str_starts_with($cast, 'decimal:')) {
            return number_format((float) $value, 2, '.', '');
        }

        if ($value instanceof DateTimeInterface) {
            if ($cast === 'date') {
                return $value->format('Y-m-d');
            }

            return CarbonImmutable::instance($value)->setTimezone('UTC')->format('Y-m-d\TH:i:s');
        }

        return (string) $value;
    }

    /** Every column on this table, in schema order. */
    protected function syncableColumns(): array
    {
        static $cache = [];

        return $cache[static::class] ??= $this->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($this->getTable());
    }
}
