<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A per-user override for the delete-item permission.
 *
 * Deleting an item is otherwise owner/manager only (AuthServiceProvider's
 * delete-item gate) - the bar closing the drawer gets, because it is the one
 * inventory action that cannot be undone by re-editing. An owner may still
 * want to hand it to one trusted staff member without promoting them to
 * manager, which also hands over discount authorisation and closing the
 * drawer - things this flag has nothing to do with.
 *
 * Defaults false: granting it is a deliberate, individual decision an owner
 * makes per person, not something a new account should start with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_delete_inventory')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_delete_inventory');
        });
    }
};
