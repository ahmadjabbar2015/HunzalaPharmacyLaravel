<?php

namespace App\Services;

use App\Exceptions\PinException;
use App\Exceptions\UserException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Staff accounts. Owner-only throughout.
 *
 * Accounts are never deleted, only deactivated: every sale, adjustment and audit
 * row points at a user, and removing the row would orphan the shop's entire
 * history of who did what. Deactivation stops the login and the PIN while
 * leaving that history intact.
 *
 * Ported from shared/services/user_service.py.
 */
class UserService
{
    /**
     * Fields an edit may touch.
     *
     * username is absent: it is the login identity and appears in the audit
     * trail. Credentials are absent too - they go through PinService, which owns
     * hashing, format rules and the audit rows that go with a change.
     */
    public const EDITABLE_FIELDS = ['full_name', 'phone', 'role'];

    public function __construct(
        private readonly PinService $pins,
        private readonly AuditService $audit,
    ) {}

    /**
     * Add a staff, manager or owner account.
     *
     * @throws UserException if the actor is not the owner, a field is blank, the
     *                       role is unknown, or the username is taken
     */
    public function create(
        User $actor,
        string $username,
        string $fullName,
        string $password,
        string $pin,
        string $deviceId,
        string $role = 'staff',
        ?string $phone = null,
    ): User {
        $this->requireOwner($actor);

        $username = trim($username);
        $fullName = trim($fullName);

        if ($username === '' || $fullName === '') {
            throw new UserException('username and full name are required');
        }

        if (! in_array($role, config('pharmacy.user_roles'), true)) {
            throw new UserException("unknown role: {$role}");
        }

        if (User::query()->withDeleted()->where('username', $username)->exists()) {
            // Including soft-deleted rows: reusing a username would silently
            // attach a new person to an old account's audit trail.
            throw new UserException("username '{$username}' already exists");
        }

        return DB::transaction(function () use ($actor, $username, $fullName, $password, $pin, $deviceId, $role, $phone) {
            $user = User::create([
                'username' => $username,
                'full_name' => $fullName,
                'phone' => $phone,
                'role' => $role,
                'origin_device_id' => $deviceId,
            ]);

            // Both credentials are set through PinService, so the format and
            // uniqueness rules cannot be bypassed by creating a user. Its
            // failures are re-thrown as UserException: the caller is creating a
            // user, and that is the error it knows how to show.
            try {
                $this->pins->setPassword($user, $password);
                $this->pins->setPin($user, $pin);
            } catch (PinException $e) {
                throw new UserException($e->getMessage(), previous: $e);
            }

            $this->audit->record(
                action: config('pharmacy.audit.user_created'),
                deviceId: $deviceId,
                userUuid: $user->uuid,
                details: ['created_by' => $actor->uuid, 'role' => $role],
            );

            Log::info('user created', ['username' => $username, 'role' => $role, 'by' => $actor->username]);

            return $user;
        });
    }

    /**
     * Edit a user's name, phone or role.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws UserException on a non-owner actor, an unknown field or role, a
     *                       blank name, or demoting the last active owner
     */
    public function update(User $actor, string $targetUuid, string $deviceId, array $changes): User
    {
        $this->requireOwner($actor);

        $target = User::find($targetUuid);

        if ($target === null) {
            throw new UserException("user '{$targetUuid}' not found");
        }

        $unknown = array_diff(array_keys($changes), self::EDITABLE_FIELDS);

        if ($unknown !== []) {
            throw new UserException('non-editable fields: '.implode(', ', $unknown));
        }

        if (array_key_exists('full_name', $changes) && trim((string) $changes['full_name']) === '') {
            throw new UserException('full name cannot be blank');
        }

        $newRole = $changes['role'] ?? null;

        if ($newRole !== null && ! in_array($newRole, config('pharmacy.user_roles'), true)) {
            throw new UserException("unknown role: {$newRole}");
        }

        if ($newRole !== null && $newRole !== $target->role && $target->role === 'owner') {
            $this->guardLastActiveOwner($target->uuid);
        }

        $oldRole = $target->role;
        $target->fill($changes)->save();

        if ($newRole !== null && $newRole !== $oldRole) {
            // A role change hands or removes the ability to manage accounts and
            // override discounts, so it is audited in its own right.
            $this->audit->record(
                action: config('pharmacy.audit.user_role_changed'),
                deviceId: $deviceId,
                userUuid: $target->uuid,
                details: ['changed_by' => $actor->uuid, 'old_role' => $oldRole, 'new_role' => $newRole],
            );
        }

        return $target;
    }

    /**
     * Disable a login and its PIN without deleting the account.
     *
     * @throws UserException on a non-owner actor, deactivating yourself, or
     *                       removing the last active owner
     */
    public function deactivate(User $actor, string $targetUuid, string $deviceId): User
    {
        $this->requireOwner($actor);

        $target = User::find($targetUuid);

        if ($target === null) {
            throw new UserException("user '{$targetUuid}' not found");
        }

        if ($target->uuid === $actor->uuid) {
            // Locking yourself out of the only account that can create accounts
            // is unrecoverable from inside the application.
            throw new UserException('you cannot deactivate your own account');
        }

        if ($target->role === 'owner') {
            $this->guardLastActiveOwner($target->uuid);
        }

        $target->is_active = false;
        $target->save();

        $this->audit->record(
            action: config('pharmacy.audit.user_deactivated'),
            deviceId: $deviceId,
            userUuid: $target->uuid,
            details: ['deactivated_by' => $actor->uuid],
        );

        Log::info('user deactivated', ['username' => $target->username, 'by' => $actor->username]);

        return $target;
    }

    /** Re-enable a previously deactivated account. */
    public function reactivate(User $actor, string $targetUuid, string $deviceId): User
    {
        $this->requireOwner($actor);

        $target = User::find($targetUuid);

        if ($target === null) {
            throw new UserException("user '{$targetUuid}' not found");
        }

        $target->is_active = true;
        $target->save();

        $this->audit->record(
            action: config('pharmacy.audit.user_reactivated'),
            deviceId: $deviceId,
            userUuid: $target->uuid,
            details: ['reactivated_by' => $actor->uuid],
        );

        return $target;
    }

    /** @return Collection<int, User> */
    public function list(bool $activeOnly = false): Collection
    {
        return User::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('full_name')
            ->get();
    }

    // -----------------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------------

    /** @throws UserException if the actor is not an owner */
    private function requireOwner(User $actor): void
    {
        if (! $actor->isOwner()) {
            throw new UserException('only the owner can manage staff accounts');
        }
    }

    /**
     * Refuse to leave the shop with no active owner.
     *
     * Without this, demoting or deactivating the last owner would lock everyone
     * out of staff management permanently - there would be no account left that
     * could create one.
     *
     * @throws UserException if no other active owner would remain
     */
    private function guardLastActiveOwner(string $excludingUuid): void
    {
        $remaining = User::query()
            ->where('role', 'owner')
            ->where('is_active', true)
            ->where('uuid', '!=', $excludingUuid)
            ->count();

        if ($remaining === 0) {
            throw new UserException('cannot remove the last active owner');
        }
    }
}
