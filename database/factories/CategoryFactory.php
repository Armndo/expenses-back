<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->word(),
            'alias' => fake()->randomElement(['🛒', '🍔', '🚗', '🏠', '🩺']),
            'order' => fake()->numberBetween(1, 20),
            'color' => fake()->hexColor(),
        ];
    }
}
