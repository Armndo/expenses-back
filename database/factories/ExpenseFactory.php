<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Expense>
 */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'date' => fake()->date(),
            'amount' => fake()->randomFloat(2, 1, 1000),
            'description' => fake()->sentence(3),
            'next' => false,
            'instalments' => null,
            'category_id' => null,
        ];
    }

    /** Billed in the next period (its effective date is `date + 1 month`). */
    public function next(): static
    {
        return $this->state(fn () => ['next' => true]);
    }

    public function instalments(int $count): static
    {
        return $this->state(fn () => ['instalments' => $count]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }
}
