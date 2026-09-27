<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Customers and their families, sales, and returns.
 *
 * A return never edits the original sale: amounts stay untouched and only
 * sales.is_returned is flagged, so the original's record hash stays stable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per phone number. "WALK-IN" is a sentinel customer.
        Schema::create('customers', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('phone_number', 30)->unique();   // immutable after creation
            $table->string('primary_contact_name', 120)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 60)->nullable();
            $table->string('email', 120)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('family_members', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('customer_uuid');
            $table->string('member_name', 120);
            // self|wife|son|daughter|mother|father|other. Named
            // relationship_type in the DB, matching the Python client's
            // attribute of the same name, so both read one column name.
            $table->string('relationship_type', 20)->nullable();
            $table->date('dob')->nullable();
            $table->text('notes')->nullable();

            $table->foreign('customer_uuid')->references('uuid')->on('customers');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('invoice_number', 40)->index();  // WEB-YYYYMMDD-NNNN
            $table->uuid('customer_uuid')->nullable();
            $table->uuid('family_member_uuid')->nullable();
            $table->date('sale_date')->index();             // Karachi business date
            $table->dateTime('sale_time');
            $table->uuid('staff_member_uuid');              // who COMPLETED the sale
            $table->uuid('session_uuid')->nullable();
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->string('discount_type', 12)->nullable();    // fixed|percentage
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->text('discount_reason')->nullable();
            $table->boolean('discount_pin_verified')->default(false);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('payment_method', 12)->nullable();   // cash|mobile
            $table->string('payment_reference', 80)->nullable();
            // Hash-excluded but IS synced: a reprint is a real event the server wants.
            $table->integer('print_count')->default(0);
            $table->boolean('receipt_pending_print')->default(false);
            $table->boolean('made_while_unsynced')->default(false);
            $table->boolean('is_returned')->default(false);

            $table->foreign('customer_uuid')->references('uuid')->on('customers');
            $table->foreign('family_member_uuid')->references('uuid')->on('family_members');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('sale_uuid');
            $table->uuid('item_uuid');
            $table->uuid('batch_uuid')->nullable();
            $table->integer('quantity');
            $table->decimal('rate', 12, 2);
            $table->decimal('amount', 12, 2);

            $table->foreign('sale_uuid')->references('uuid')->on('sales');
            $table->foreign('item_uuid')->references('uuid')->on('items');
            $table->foreign('batch_uuid')->references('uuid')->on('item_batches');
        });

        Schema::create('returns', function (Blueprint $table) {
            $table->syncColumns();
            $table->string('return_number', 40);            // WEB-R-YYYYMMDD-NNNN
            $table->uuid('original_sale_uuid');
            $table->uuid('customer_uuid')->nullable();
            $table->uuid('family_member_uuid')->nullable();
            $table->date('return_date')->index();
            $table->dateTime('return_time');
            $table->uuid('staff_member_uuid');
            $table->uuid('session_uuid')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('refund_method', 12)->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->integer('print_count')->default(0);

            $table->foreign('original_sale_uuid')->references('uuid')->on('sales');
            $table->foreign('customer_uuid')->references('uuid')->on('customers');
            $table->foreign('family_member_uuid')->references('uuid')->on('family_members');
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->syncColumns();
            $table->uuid('return_uuid');
            $table->uuid('sale_item_uuid')->nullable();
            $table->uuid('item_uuid');
            $table->uuid('batch_uuid')->nullable();
            $table->integer('quantity');
            $table->decimal('rate', 12, 2);                 // the ORIGINAL sale rate
            $table->decimal('amount', 12, 2);

            $table->foreign('return_uuid')->references('uuid')->on('returns');
            $table->foreign('sale_item_uuid')->references('uuid')->on('sale_items');
            $table->foreign('item_uuid')->references('uuid')->on('items');
            $table->foreign('batch_uuid')->references('uuid')->on('item_batches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('family_members');
        Schema::dropIfExists('customers');
    }
};
