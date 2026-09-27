<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'item_code' => strtoupper(fake()->unique()->bothify('???-###')),
            'item_name' => fake()->words(2, true),
            'manufacturer' => fake()->company(),
            'category' => fake()->randomElement(['Analgesic', 'Antibiotic', 'Antacid', 'Vitamin']),
            'purchase_price' => fake()->randomFloat(2, 5, 200),
            'sales_price' => fake()->randomFloat(2, 10, 300),
            'reorder_level' => 10,
            'unit_of_measure' => 'tablet',
            'is_narcotic' => false,
            'is_active' => true,

            // Deliberately NOT set from the ledger. It is a cache, and several
            // tests exist specifically to prove a stale value here is ignored.
            'current_stock_qty' => 0,
        ];
    }

    public function narcotic(): static
    {
        return $this->state(['is_narcotic' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
