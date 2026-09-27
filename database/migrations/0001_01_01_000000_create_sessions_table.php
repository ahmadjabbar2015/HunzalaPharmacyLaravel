<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The framework's HTTP session store (config/session.php, SESSION_DRIVER=database).
 *
 * This is the ONLY thing that owns the name `sessions`. The shop's cash drawer -
 * the domain concept a pharmacy actually calls a "session" - lives in
 * `drawer_sessions` instead, so the two can never be mistaken for each other
 * in a query, a migration, or a backup.
 *
 * Not a syncColumns() table: a browser session is local to this server and has
 * no business being pushed to a till or reconciled against anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // uuid, not foreignId: users are keyed by a CHAR(36) uuid (RULE 1),
            // so the default bigint column would never match. No FK constraint -
            // a stale session row must not block deleting a user.
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
