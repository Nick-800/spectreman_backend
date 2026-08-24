<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'odoo_partner_id' => (string) fake()->unique()->numberBetween(100, 99999),
            'phone' => '+21891'.fake()->unique()->numerify('#######'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'customer',
            'account_state' => 'active',
        ];
    }

    public function driver(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'driver',
        ]);
    }

    public function restaurant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'restaurant',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_state' => 'suspended',
        ]);
    }
}
