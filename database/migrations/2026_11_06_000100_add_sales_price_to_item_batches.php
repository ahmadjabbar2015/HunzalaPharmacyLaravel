<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A batch's own selling price, overriding the item's.
 *
 * The item's sales_price is one figure for every batch, but a batch bought
 * at a different cost (stock is routinely reordered before the shelf is
 * empty, so an old and a new batch sit side by side) is routinely sold at a
 * different price too - and until now, repricing the item to match a new
 * delivery silently repriced the old batch's remaining stock as well, even
 * though that stock was bought and should sell at the old cost.
 *
 * Nullable, like retail_price: blank means "use the item's own price",
 * which is every batch before this migration and every one received
 * without bothering to set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_batches', function (Blueprint $table) {
            $table->decimal('sales_price', 12, 2)->nullable()->after('purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('item_batches', function (Blueprint $table) {
            $table->dropColumn('sales_price');
        });
    }
};
