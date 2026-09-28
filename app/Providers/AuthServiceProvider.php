<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/*
 * Who may do what.
 *
 * Three roles, and a plain `role` column with Gates rather than a permissions
 * package. The shop has three kinds of person and will still have three kinds of
 * person in five years; a permission table would add a screen nobody asks for
 * and an indirection between "manager" and what a manager can do.
 *
 *   staff   - serve customers. Ring sales, take returns, receive stock.
 *   manager - the above, plus authorise a large discount, close the drawer, and
 *             read the reports.
 *   owner   - the above, plus manage staff accounts and settings.
 *
 * Every gate is named for the ACTION, not the role. `can('close-drawer')` at the
 * call site survives a decision to let senior staff close up; `isManager()`
 * would have to be found and changed everywhere.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Nothing at all for a deactivated account, whatever its role.
         *
         * A `before` callback, so this cannot be forgotten at one call site. The
         * login already refuses them, but a session open when the owner
         * deactivates someone must stop working on its next request rather than
         * at its next login.
         */
        Gate::before(function (User $user, string $ability) {
            return $user->is_active ? null : false;
        });

        // Selling. Every active member of staff, which is the point of the till.
        Gate::define('sell', fn (User $user) => true);

        // Returns are staff-level too: a customer standing at the counter with a
        // faulty box should not have to wait for a manager. The refund is capped
        // at what was sold and every return is attributed by PIN, which is the
        // real control.
        Gate::define('process-return', fn (User $user) => true);

        // Receiving stock and adjusting it. Staff unpack the deliveries.
        Gate::define('manage-inventory', fn (User $user) => true);
        Gate::define('manage-customers', fn (User $user) => true);
        Gate::define('manage-purchasing', fn (User $user) => true);

        // Opening the drawer is staff-level - whoever arrives first does it.
        Gate::define('open-drawer', fn (User $user) => true);

        /*
         * CLOSING it is not. The close is where the variance is computed, and
         * letting the person who might be short also be the one to record and
         * explain it removes the only check the shop has.
         */
        Gate::define('close-drawer', fn (User $user) => $user->isManagerOrOwner());

        // A discount above the configured threshold needs a manager's PIN. Small
        // goodwill discounts stay a staff judgement call.
        Gate::define('authorise-discount', fn (User $user) => $user->isManagerOrOwner());

        // Reports show margins, staff-by-staff takings and variance history.
        Gate::define('view-reports', fn (User $user) => $user->isManagerOrOwner());

        // The integrity screen can trigger a full cache rebuild.
        Gate::define('view-integrity', fn (User $user) => $user->isManagerOrOwner());

        // Staff accounts and shop settings are the owner's alone. UserService
        // enforces this again at the service boundary - the Gate decides what the
        // UI offers, the service decides what actually happens.
        Gate::define('manage-staff', fn (User $user) => $user->isOwner());
        Gate::define('manage-settings', fn (User $user) => $user->isOwner());
    }
}
