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
            // 1 keeps every existing test's arithmetic: a piece IS a pack.
            'pack_size' => 1,
            'retail_price' => null,
            'max_discount_percent' => null,
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

    /** Sold by the piece out of a pack - the normal case for medicines. */
    public function packOf(int $pieces): static
    {
        return $this->state(['pack_size' => $pieces]);
    }

    /** Capped at $percent off, or 0 for "never discount this one". */
    public function discountCappedAt(float $percent): static
    {
        return $this->state(['max_discount_percent' => $percent]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
