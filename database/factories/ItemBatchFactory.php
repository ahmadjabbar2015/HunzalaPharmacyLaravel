<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemBatch;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemBatch>
 */
class ItemBatchFactory extends Factory
{
    protected $model = ItemBatch::class;

    public function definition(): array
    {
        return [
            'item_uuid' => Item::factory(),
            'batch_number' => strtoupper(fake()->unique()->bothify('B-####')),

            // Comfortably in the future by default, so a test that does not care
            // about expiry never trips the FEFO expired-batch skip by accident.
            'expiry_date' => BusinessDate::for()->addYear()->toDateString(),
            'received_date' => BusinessDate::today(),
            'received_qty' => 0,
            'current_qty' => 0,
            'purchase_price' => fake()->randomFloat(2, 5, 200),
            'is_expired' => false,
        ];
    }

    /** Expires on a given day; pass a past date to make it expired. */
    public function expiring(string $date): static
    {
        return $this->state(['expiry_date' => $date]);
    }

    public function expired(): static
    {
        return $this->state([
            'expiry_date' => BusinessDate::for()->subDay()->toDateString(),
            'is_expired' => true,
        ]);
    }
}
