<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Income>
 */
class IncomeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'date' => fake()->date(),
            'amount' => fake()->randomFloat(2, 1, 5000),
            'description' => fake()->sentence(3),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }
}
