<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The catalogue, its received lots, and the append-only stock ledger.
 *
 * RULE 2 lives here: items.current_stock_qty and item_batches.current_qty are
 * caches only. The real quantity is SUM(stock_transactions.qty_change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('item_code', 50)->unique();      // immutable - it prints on receipts
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('item_name', 200);
            $table->string('manufacturer', 200)->nullable();
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('purchase_price', 10, 2)->default(0);
            $table->decimal('sales_price', 10, 2)->default(0);
            // CACHE ONLY - never hashed, never pushed. Recomputed from the ledger.
            $table->integer('current_stock_qty')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->string('unit_of_measure', 30)->nullable();
            $table->string('location', 60)->nullable();
            $table->boolean('is_narcotic')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('item_batches', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('item_uuid');
            $table->string('batch_number', 80);
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date');                    // required - FEFO depends on it
            $table->integer('received_qty')->default(0);
            $table->integer('current_qty')->default(0);     // CACHE ONLY
            $table->decimal('purchase_price', 10, 2)->default(0);
            $table->date('received_date')->nullable();
            $table->uuid('supplier_uuid')->nullable();      // deliberately no FK
            $table->boolean('is_expired')->default(false);

            $table->foreign('item_uuid')->references('uuid')->on('items');
            $table->unique(['item_uuid', 'batch_number'], 'uq_item_batch');
        });

        // THE LEDGER. Append-only: rows are never updated or deleted.
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('item_uuid')->index();
            $table->uuid('batch_uuid')->nullable()->index();
            $table->string('transaction_type', 20);         // purchase|sale|return|adjustment|damage|expiry
            $table->integer('qty_change');                  // signed; never zero
            $table->uuid('reference_uuid')->nullable();
            $table->string('reference_type', 30)->nullable();
            $table->text('reason')->nullable();             // required for adjustments
            $table->uuid('performed_by_user_uuid')->nullable();
            $table->uuid('session_uuid')->nullable();
            $table->dateTime('transaction_date');

            $table->foreign('item_uuid')->references('uuid')->on('items');
            $table->foreign('batch_uuid')->references('uuid')->on('item_batches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('item_batches');
        Schema::dropIfExists('items');
    }
};
