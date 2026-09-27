<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity, the shared cash drawer, shop settings, and the two append-only
 * accountability trails. Column names mirror shared/models/ exactly so a future
 * sync layer and the existing MySQL data both still fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('username', 50)->unique();
            $table->string('password_hash', 255)->nullable();
            $table->string('pin_hash', 255)->nullable();
            $table->string('full_name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('role', 20)->default('staff');   // staff|manager|owner
            $table->boolean('is_active')->default(true);
            // Volatile per-device anti-fraud counters: never hashed, never synced.
            $table->integer('pin_failed_attempts')->default(0);
            $table->dateTime('pin_locked_until')->nullable();
        });

        // The shop's shared cash drawer - one per evening, not an HTTP session.
        // Deliberately NOT called `sessions`: that name is reserved for the
        // framework's own HTTP session store (config/session.php), and the
        // Python client uses the same `drawer_sessions` name for this table.
        Schema::create('drawer_sessions', function (Blueprint $table) {
            $table->syncColumns();
            $table->date('session_date');                   // dated by the OPENING business date
            $table->uuid('opened_by_user_uuid');
            $table->dateTime('opened_at');
            $table->decimal('opening_float', 12, 2)->nullable();
            $table->uuid('closed_by_user_uuid')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->decimal('closing_cash_counted', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->string('status', 10)->default('open');  // open|closed
            $table->text('notes')->nullable();
            $table->boolean('is_provisional')->default(false);
        });

        // Single row.
        Schema::create('settings', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('shop_name', 200)->nullable();
            $table->text('shop_address')->nullable();
            $table->string('shop_phone', 40)->nullable();
            $table->decimal('discount_pin_threshold', 12, 2)->default(100);
            $table->integer('low_stock_threshold_default')->default(10);
            $table->integer('expiry_alert_days')->default(30);
            $table->integer('sync_interval_seconds')->default(30);
            $table->string('cloud_api_url', 255)->nullable();
            $table->string('device_id', 20)->nullable();
            $table->string('printer_name', 120)->nullable();
            $table->string('receipt_width', 10)->default('80mm');   // 58mm|80mm
        });

        // Append-only. Rows are never updated or deleted.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('action', 80);
            $table->string('entity_type', 50)->nullable();
            $table->uuid('entity_uuid')->nullable();
            $table->uuid('user_uuid')->nullable();
            $table->uuid('session_uuid')->nullable();
            $table->text('details')->nullable();            // compact sorted-key JSON
            $table->dateTime('occurred_at');
        });

        // Append-only. Every receipt and report print, for fraud detection.
        Schema::create('print_log', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('document_type', 40);            // receipt|report|return
            $table->uuid('reference_uuid')->nullable();
            $table->uuid('printed_by_user_uuid')->nullable();
            $table->uuid('session_uuid')->nullable();
            $table->boolean('pin_verified')->default(false);
            $table->integer('print_count_at_time')->default(0);
            $table->dateTime('printed_at');
        });

        // Who was on the floor during a session. Informational, never blocking.
        Schema::create('staff_presence', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('session_uuid');
            $table->uuid('user_uuid');
            $table->dateTime('checked_in_at');
            $table->dateTime('checked_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_presence');
        Schema::dropIfExists('print_log');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('drawer_sessions');
        Schema::dropIfExists('users');
    }
};
