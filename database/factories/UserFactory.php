<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('03##-#######'),
            'role' => 'staff',
            'password_hash' => Hash::make('password'),
            'is_active' => true,
        ];
    }

    public function manager(): static
    {
        return $this->state(['role' => 'manager']);
    }

    public function owner(): static
    {
        return $this->state(['role' => 'owner']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A PIN this user can be identified by at the till. Explicit rather than a
     * default, because PINs must be unique among active users and a factory
     * handing every user the same one would violate that the moment a test
     * created two.
     */
    public function withPin(string $pin): static
    {
        return $this->state(['pin_hash' => Hash::make($pin)]);
    }
}
