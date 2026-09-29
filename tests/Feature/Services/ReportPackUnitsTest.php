<?php

/*
 * Reports against items sold in packs.
 *
 * The pack feature changed what purchase_price MEANS on an item - it is now the
 * cost of a whole pack, while the ledger and every batch still count pieces. The
 * report code was written for that and says so in its comments, but nothing
 * proved it: every existing report test uses pack_size 1, where the two units
 * are the same number and a factor-of-pack_size error is invisible.
 *
 * These are the tests that would fail if someone multiplied a piece count by a
 * pack price - the error ReportService's own comment calls "invisible until
 * someone reconciles against an accountant".
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\User;
use App\Services\CartLine;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\Money;

beforeEach(function () {
    $this->reports = app(ReportService::class);
    $this->stock = app(StockService::class);
    $this->staff = User::factory()->create(['username' => 'ahmed']);
});

/**
 * An item sold in packs, with stock received at a per-piece cost.
 *
 * The per-piece batch cost is what InventoryController stores after converting
 * from whatever unit the delivery note used, so this mirrors the real path.
 */
function packedItemWithStock(int $packSize, string $packCost, string $packPrice, int $pieces): Item
{
    $item = Item::factory()->create([
        'item_code' => 'PACKED-'.$packSize,
        'item_name' => 'Packed Item '.$packSize,
        'pack_size' => $packSize,
        // Both prices are per PACK on the item, which is what the pack feature
        // made them mean.
        'purchase_price' => $packCost,
        'sales_price' => $packPrice,
    ]);

    $batch = ItemBatch::factory()->for($item, 'item')->create([
        // Per PIECE on the batch - the unit the ledger counts in.
        'purchase_price' => $item->pieceCost(),
    ]);

    app(StockService::class)->receive(
        itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: $pieces, deviceId: DEVICE
    );

    return $item->fresh();
}

// ---------------------------------------------------------------------------
// stock valuation
// ---------------------------------------------------------------------------

it('values a packed shelf per piece, not per pack', function () {
    /*
     * 10 boxes of 20 received as 200 tablets. The box costs 400.00, so a tablet
     * costs 20.00 and the shelf is worth 4,000.00.
     *
     * Multiplying the 200-piece count by the 400.00 pack cost would value it at
     * 80,000.00 - twenty times over, and a plausible-looking number.
     */
    packedItemWithStock(packSize: 20, packCost: '400.00', packPrice: '600.00', pieces: 200);

    $report = $this->reports->inventory();
    $row = collect($report['items'])->firstWhere('item_code', 'PACKED-20');

    expect($row['derived_qty'])->toBe(200)
        ->and($row['purchase_price'])->toBe('20.00')     // per piece
        ->and($row['sales_price'])->toBe('30.00')        // per piece
        ->and($row['stock_value'])->toBe('4000.00')
        ->and($report['total_value'])->toBe('4000.00');
});

it('reports the quantity and the prices in the same unit', function () {
    // A valuation report that mixed units would be read wrong by whoever it is
    // for - the piece count beside a pack price invites multiplying them.
    packedItemWithStock(packSize: 12, packCost: '120.00', packPrice: '240.00', pieces: 36);

    $row = collect($this->reports->inventory()['items'])->firstWhere('item_code', 'PACKED-12');

    expect($row['pack_size'])->toBe(12)
        ->and($row['derived_qty'])->toBe(36)
        // 36 pieces x 10.00 a piece, NOT 36 x 120.00.
        ->and(Money::multiply($row['purchase_price'], $row['derived_qty']))
        ->toBe($row['stock_value']);
});

it('values an unpacked item exactly as before', function () {
    // The compatibility case: pack_size 1 means piece cost and pack cost are the
    // same number, and the figure must not have moved.
    $item = Item::factory()->create(['item_code' => 'PLAIN', 'purchase_price' => '30.00', 'sales_price' => '45.00']);
    $batch = ItemBatch::factory()->for($item, 'item')->create(['purchase_price' => '30.00']);
    $this->stock->receive(itemUuid: $item->uuid, batchUuid: $batch->uuid, quantity: 10, deviceId: DEVICE);

    $row = collect($this->reports->inventory()['items'])->firstWhere('item_code', 'PLAIN');

    expect($row['stock_value'])->toBe('300.00')
        ->and($row['purchase_price'])->toBe('30.00');
});

// ---------------------------------------------------------------------------
// cost of goods sold
// ---------------------------------------------------------------------------

it('costs a pack sale from the batch, per piece', function () {
    /*
     * Sell a whole box: 20 tablets at 30.00 each is 600.00 of revenue, against
     * 20 tablets at 20.00 each of cost. Gross profit 200.00.
     *
     * Taking the cost from items.purchase_price - now a PACK cost - would charge
     * 400.00 per tablet and report a loss of 7,400.00 on a profitable sale.
     */
    $item = packedItemWithStock(packSize: 20, packCost: '400.00', packPrice: '600.00', pieces: 200);

    app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 20, $item->piecePrice(), $item->batches()->first()->uuid)],
        staffMemberUuid: $this->staff->uuid,
        deviceId: DEVICE,
    );

    $today = BusinessDate::today();
    $report = $this->reports->profitLoss($today, $today);
    $row = collect($report['rows'])->firstWhere('item_code', 'PACKED-20');

    expect($row['qty_sold'])->toBe(20)
        ->and($row['revenue'])->toBe('600.00')
        ->and($row['cogs'])->toBe('400.00')
        ->and($row['gross_profit'])->toBe('200.00')
        ->and($report['total_gross_profit'])->toBe('200.00');
});

it('costs singles and a whole pack identically', function () {
    // Twenty single tablets and one box of twenty must produce the same revenue,
    // the same cost and the same profit - the same invariant the till enforces.
    $item = packedItemWithStock(packSize: 20, packCost: '400.00', packPrice: '600.00', pieces: 400);
    $batch = $item->batches()->first()->uuid;

    // One box.
    app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 20, $item->piecePrice(), $batch)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // Twenty singles, on separate sales.
    foreach (range(1, 20) as $ignored) {
        app(SaleService::class)->create(
            lines: [new CartLine($item->uuid, 1, $item->piecePrice(), $batch)],
            staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
        );
    }

    $today = BusinessDate::today();
    $row = collect($this->reports->profitLoss($today, $today)['rows'])->firstWhere('item_code', 'PACKED-20');

    // 40 tablets either way: 1200.00 revenue, 800.00 cost, 400.00 profit.
    expect($row['qty_sold'])->toBe(40)
        ->and($row['revenue'])->toBe('1200.00')
        ->and($row['cogs'])->toBe('800.00')
        ->and($row['gross_profit'])->toBe('400.00');
});

it('keeps the cost the batch was received at after the pack price changes', function () {
    /*
     * COGS comes from the batch the goods actually came from, not from the
     * item's current catalogue cost. A supplier raising the pack price must not
     * retrospectively change the profit on stock already sold.
     */
    $item = packedItemWithStock(packSize: 10, packCost: '100.00', packPrice: '200.00', pieces: 100);

    app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 10, '20.00', $item->batches()->first()->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    // The supplier doubles the pack cost afterwards.
    $item->forceFill(['purchase_price' => '200.00'])->save();

    $today = BusinessDate::today();
    $row = collect($this->reports->profitLoss($today, $today)['rows'])->firstWhere('item_code', 'PACKED-10');

    // Still costed at the 10.00 a piece the batch was received at.
    expect($row['cogs'])->toBe('100.00')
        ->and($row['gross_profit'])->toBe('100.00');
});

it('costs a pack whose price does not divide evenly at the batch rate', function () {
    // A pack of 3 at 100.00 is 33.33 a piece, so three pieces cost 99.99. The
    // penny is in valuation, not in cash, and the report must not invent it back.
    $item = packedItemWithStock(packSize: 3, packCost: '100.00', packPrice: '150.00', pieces: 30);

    app(SaleService::class)->create(
        lines: [new CartLine($item->uuid, 3, $item->piecePrice(), $item->batches()->first()->uuid)],
        staffMemberUuid: $this->staff->uuid, deviceId: DEVICE,
    );

    $today = BusinessDate::today();
    $row = collect($this->reports->profitLoss($today, $today)['rows'])->firstWhere('item_code', 'PACKED-3');

    expect($row['cogs'])->toBe('99.99')
        // 150.00 / 3 = 50.00 a piece, so 3 pieces is 150.00 of revenue.
        ->and($row['revenue'])->toBe('150.00')
        ->and($row['gross_profit'])->toBe('50.01');
});
