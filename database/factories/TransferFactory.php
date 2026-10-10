<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transfer>
 */
class TransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'from_source_id' => Source::factory(),
            'to_source_id' => Source::factory(),
            'amount' => fake()->randomFloat(2, 1, 5000),
            'received_amount' => null,
            'date' => fake()->date(),
            'description' => fake()->sentence(3),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }

    /** Between two existing sources. */
    public function between(Source $from, Source $to): static
    {
        return $this->state(fn () => ['from_source_id' => $from->id, 'to_source_id' => $to->id]);
    }
}
