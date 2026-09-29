<?php

/*
 * The dashboard.
 *
 * It exists to answer three questions at a glance - is the drawer open, what
 * has the shop taken today, and is anything wrong with the stock - so what is
 * pinned here is that those figures are actually populated. A dashboard quietly
 * showing zero takings on a busy day is worse than one that is missing.
 */

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\User;
use App\Services\CartLine;
use App\Services\SaleService;
use App\Services\StockService;

beforeEach(function () {
    $this->staff = User::factory()->create(['username' => 'ahmed', 'full_name' => 'Ahmed Ali']);

    $this->item = Item::factory()->create(['item_code' => 'PANADOL-500', 'item_name' => 'Panadol 500mg']);
    $this->batch = ItemBatch::factory()->for($this->item, 'item')->create();

    app(StockService::class)->receive(
        itemUuid: $this->item->uuid, batchUuid: $this->batch->uuid, quantity: 100, deviceId: DEVICE
    );

    $this->actingAs($this->staff);
});

it('loads with nothing having happened yet', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee('0.00');
});

/*
 * This is the regression test for a real defect found while building the
 * reports. sale_date is a DATE column, but Eloquent's `date` cast writes it
 * back through the model's datetime format, so the stored value can carry a
 * zero time component. A bare `where('sale_date', $today)` then matches every
 * row in MySQL, which coerces it away, and no row at all in SQLite, which does
 * not - so the dashboard's takings were engine-dependent. See the note in
 * ReportService::dailySales().
 */
it("counts today's takings", function () {
    foreach ([2, 3] as $qty) {
        app(SaleService::class)->create(
            lines: [new CartLine($this->item->uuid, $qty, '12.00', $this->batch->uuid)],
            staffMemberUuid: $this->staff->uuid,
            deviceId: DEVICE,
        );
    }

    // 5 × 12.00 across two sales.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('60.00')
        ->assertSee('2');
});
