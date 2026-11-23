<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two more per-user overrides, alongside can_delete_inventory - one that
 * takes access away from the staff default, one that grants a slice of
 * what only a manager/owner gets otherwise.
 *
 * can_manage_inventory defaults TRUE: today every staff account can add
 * items, receive stock and adjust it (AuthServiceProvider's manage-inventory
 * gate is wide open), so true preserves that for every existing account.
 * An owner can narrow it to just selling and printing a bill for one
 * till-only hire without touching everyone else.
 *
 * can_view_daily_sale_detail defaults FALSE: no staff account can see any
 * report today (view-reports is manager/owner only), so false preserves
 * that too. An owner can hand one staff member just the Daily Sale Detail
 * report - the print sheet, not the breakdowns - without handing over
 * reports.index's wider view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_inventory')->default(true)->after('can_delete_inventory');
            $table->boolean('can_view_daily_sale_detail')->default(false)->after('can_manage_inventory');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_manage_inventory', 'can_view_daily_sale_detail']);
        });
    }
};
