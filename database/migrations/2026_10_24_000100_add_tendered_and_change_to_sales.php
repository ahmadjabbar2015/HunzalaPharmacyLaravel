<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the customer handed over, and the change given back, for a cash sale.
 *
 * net_amount already proves what was OWED. It does not prove what was PAID
 * WITH or handed back - and "I gave you a thousand, you only gave me change
 * for five hundred" is a dispute the till needs an answer for after the fact,
 * not just a number flashed on screen and then lost.
 *
 * Nullable, matching shared/models/sale.py: most sales (every one before this
 * migration, and every non-cash payment) never set these, and null means "not
 * recorded" rather than "handed over nothing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('amount_tendered', 12, 2)->nullable()->after('payment_reference');
            $table->decimal('change_due', 12, 2)->nullable()->after('amount_tendered');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['amount_tendered', 'change_due']);
        });
    }
};
