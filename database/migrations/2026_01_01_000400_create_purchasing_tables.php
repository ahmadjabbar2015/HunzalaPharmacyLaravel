<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Suppliers, purchase orders, goods receipts, and supplier payments.
 *
 * A supplier's outstanding balance is never stored: it is derived every time as
 * opening_balance + purchases - payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('supplier_name', 120);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0);  // not editable after creation
            $table->string('payment_terms', 120)->nullable();
            $table->boolean('is_active')->default(true);
        });

        // Ordering only - a PO moves no stock until it is received against.
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('po_number', 40)->unique();      // WEB-PO-YYYYMMDD-NNNN
            $table->uuid('supplier_uuid');
            $table->date('order_date');
            $table->string('status', 20)->default('ordered');   // draft|ordered|partially_received|received|cancelled
            $table->text('notes')->nullable();

            $table->foreign('supplier_uuid')->references('uuid')->on('suppliers');
        });

        Schema::create('po_items', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('po_uuid');
            $table->uuid('item_uuid');
            $table->integer('quantity_ordered');
            $table->decimal('unit_cost', 10, 2);
            // Real data, not a cache: it is hashed and synced. Over-receipt is
            // rejected against (quantity_ordered - quantity_received).
            $table->integer('quantity_received')->default(0);

            $table->foreign('po_uuid')->references('uuid')->on('purchase_orders');
            $table->foreign('item_uuid')->references('uuid')->on('items');
        });

        // A goods receipt. This is what actually moves stock.
        Schema::create('purchases', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('purchase_number', 40)->unique();    // WEB-GRN-YYYYMMDD-NNNN
            $table->uuid('supplier_uuid');
            $table->uuid('po_uuid')->nullable();                // null = direct receipt, no PO
            $table->date('purchase_date');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('invoice_reference', 80)->nullable();

            $table->foreign('supplier_uuid')->references('uuid')->on('suppliers');
            $table->foreign('po_uuid')->references('uuid')->on('purchase_orders');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('purchase_uuid');
            $table->uuid('po_item_uuid')->nullable();
            $table->uuid('item_uuid');
            $table->uuid('batch_uuid');                     // every receipt lands in a batch
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('amount', 12, 2);

            $table->foreign('purchase_uuid')->references('uuid')->on('purchases');
            $table->foreign('po_item_uuid')->references('uuid')->on('po_items');
            $table->foreign('item_uuid')->references('uuid')->on('items');
            $table->foreign('batch_uuid')->references('uuid')->on('item_batches');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('supplier_uuid');
            $table->decimal('amount', 12, 2);               // negatives allowed as corrections
            $table->date('payment_date');
            $table->string('payment_method', 20);           // cash|mobile|bank_transfer|cheque
            $table->string('reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('performed_by_user_uuid')->nullable();

            $table->foreign('supplier_uuid')->references('uuid')->on('suppliers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('po_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
