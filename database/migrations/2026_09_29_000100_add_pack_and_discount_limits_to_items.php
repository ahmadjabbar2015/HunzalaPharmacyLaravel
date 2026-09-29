<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pack size, per-piece pricing, and a per-item discount ceiling.
 *
 * Medicines are bought by the pack and sold by the piece. Before this the
 * catalogue had one flat unit, so a box of 10 tablets and a single tablet were
 * the same row with the same price, and there was no way to receive "5 packs"
 * without a mental arithmetic error at the keyboard.
 *
 * The ledger is NOT changed. Stock stays counted in PIECES, which is what every
 * existing stock_transactions row already means: pack_size defaults to 1, so
 * every item in the catalogue today keeps exactly the quantity and the price it
 * has now. pack_size is a multiplier applied when goods are RECEIVED, never a
 * second unit the ledger has to reason about - a second unit is how a stock
 * figure ends up right in packs and wrong in pieces.
 *
 * Pricing, after the change:
 *   purchase_price  cost of ONE PACK        (what the supplier invoices)
 *   sales_price     price of ONE PACK       (what a whole box sells for)
 *   retail_price    price of ONE PIECE      (null => sales_price / pack_size)
 *
 * At pack_size 1 all three are the same number, which is why the existing rows
 * need no data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Pieces in a pack: tablets in a box, ml in a bottle, sachets in a
            // strip. 1 means the item is only ever sold whole.
            $table->integer('pack_size')->default(1)->after('sales_price');

            // Per-piece price. Nullable because the usual case is "just divide
            // the pack price" and a stored copy would go stale the moment the
            // pack price moved. Set it only to override that division - which
            // shops do, because 8.33 a tablet is sold at 10.
            $table->decimal('retail_price', 10, 2)->nullable()->after('pack_size');

            /*
             * The most this item may be discounted, as a percentage.
             *
             * Nullable, and null means no item-level ceiling - NOT zero. Zero is
             * a real and different instruction: "this one never goes down in
             * price", which is what a shop wants on narcotics and on anything
             * sold at cost. Defaulting to 0 instead of null would silently ban
             * every discount in the shop on the day this migration ran.
             */
            $table->decimal('max_discount_percent', 5, 2)->nullable()->after('retail_price');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['pack_size', 'retail_price', 'max_discount_percent']);
        });
    }
};
