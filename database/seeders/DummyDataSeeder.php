<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use App\Support\BusinessDate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Throwaway data for a fresh install: three logins, a shelf of stock, a few
 * customers and suppliers. Enough to click around a deployment that has nothing
 * in it, and nothing a real shop would want to keep.
 *
 *     php artisan db:seed --class=DummyDataSeeder
 *
 * Safe to re-run: every row is keyed on its natural unique column, so a second
 * run tops nothing up and collides with nothing.
 *
 * NOT for production. The passwords and PINs below are public knowledge.
 */
class DummyDataSeeder extends Seeder
{
    /**
     * Stock is never written as a quantity - RULE 2, it is derived from the
     * ledger - so the batches below are received through StockService and the
     * caches follow from the transactions it writes.
     */
    public function __construct(private readonly StockService $stock) {}

    public function run(): void
    {
        $deviceId = config('pharmacy.device_id');

        $owner = $this->user('owner', 'Ayesha Khan', 'owner', '1234');
        $this->user('manager', 'Bilal Aslam', 'manager', '2345');
        $this->user('staff', 'Hina Raza', 'staff', '3456');

        // PINs are unique among ACTIVE users, so each of the three gets its own.

        foreach ($this->shelf() as [$code, $name, $maker, $category, $cost, $price, $qty]) {
            $item = Item::firstOrCreate(
                ['item_code' => $code],
                [
                    'item_name' => $name,
                    'manufacturer' => $maker,
                    'category' => $category,
                    'purchase_price' => $cost,
                    'sales_price' => $price,
                    'reorder_level' => 10,
                    'unit_of_measure' => 'tablet',
                    'is_narcotic' => false,
                    'is_active' => true,
                ],
            );

            if (! $item->wasRecentlyCreated) {
                continue;
            }

            // Two batches per item with different expiries, so FEFO has
            // something to actually choose between.
            foreach ([['B-'.$code.'-1', 6, 0.6], ['B-'.$code.'-2', 18, 0.4]] as [$batchNo, $months, $share]) {
                $batchQty = max(1, (int) round($qty * $share));

                $batch = ItemBatch::create([
                    'item_uuid' => $item->uuid,
                    'batch_number' => $batchNo,
                    'expiry_date' => BusinessDate::for()->addMonths($months)->toDateString(),
                    'received_date' => BusinessDate::today(),
                    'received_qty' => $batchQty,
                    'purchase_price' => $cost,
                    'is_expired' => false,
                ]);

                $this->stock->receive(
                    itemUuid: $item->uuid,
                    batchUuid: $batch->uuid,
                    quantity: $batchQty,
                    deviceId: $deviceId,
                    performedByUserUuid: $owner->uuid,
                    reason: 'dummy opening stock',
                );
            }
        }

        foreach ([
            ['0300-1112223', 'Farhan Iqbal', 'Lahore'],
            ['0301-4445556', 'Sana Mirza', 'Karachi'],
            ['0302-7778889', 'Usman Tariq', 'Rawalpindi'],
        ] as [$phone, $contact, $city]) {
            Customer::firstOrCreate(
                ['phone_number' => $phone],
                ['primary_contact_name' => $contact, 'city' => $city, 'is_active' => true],
            );
        }

        foreach ([
            ['Getz Pharma Distributors', 'Kamran Shah', '042-35400001', '30 days'],
            ['Highnoon Wholesale', 'Nadia Butt', '042-35400002', 'cash on delivery'],
        ] as [$supplier, $contact, $phone, $terms]) {
            Supplier::firstOrCreate(
                ['supplier_name' => $supplier],
                [
                    'contact_person' => $contact,
                    'phone' => $phone,
                    'payment_terms' => $terms,
                    'opening_balance' => 0,
                    'is_active' => true,
                ],
            );
        }

        $this->command?->info('Dummy data seeded. Logins: owner / manager / staff, password "password".');
    }

    private function user(string $username, string $fullName, string $role, string $pin): User
    {
        return User::firstOrCreate(
            ['username' => $username],
            [
                'full_name' => $fullName,
                'role' => $role,
                'password_hash' => Hash::make('password'),
                'pin_hash' => Hash::make($pin),
                'phone' => '0300-0000000',
                'is_active' => true,
            ],
        );
    }

    /** [item_code, name, manufacturer, category, cost, price, opening qty] */
    private function shelf(): array
    {
        return [
            ['PAN-001', 'Panadol 500mg', 'GSK', 'Analgesic', 2.50, 4.00, 400],
            ['BRU-002', 'Brufen 400mg', 'Abbott', 'Analgesic', 4.00, 6.50, 240],
            ['AUG-003', 'Augmentin 625mg', 'GSK', 'Antibiotic', 28.00, 38.00, 120],
            ['AZI-004', 'Azomax 500mg', 'Getz Pharma', 'Antibiotic', 45.00, 60.00, 90],
            ['RIS-005', 'Risek 20mg', 'Getz Pharma', 'Antacid', 12.00, 18.00, 150],
            ['MOT-006', 'Motilium 10mg', 'Highnoon', 'Antacid', 6.00, 9.00, 180],
            ['CAL-007', 'Calpol Syrup 120ml', 'GSK', 'Analgesic', 55.00, 75.00, 60],
            ['NEU-008', 'Neurobion Forte', 'Merck', 'Vitamin', 15.00, 22.00, 200],
            ['CAC-009', 'CAC-1000 Plus', 'Hilton', 'Vitamin', 180.00, 230.00, 40],
            ['VEN-010', 'Ventolin Inhaler', 'GSK', 'Respiratory', 320.00, 395.00, 25],
        ];
    }
}
