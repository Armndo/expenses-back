<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Source>
 */
class SourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->company(),
            'cutoff' => null,
        ];
    }

    /** A source billed from `$day` (see "Billing period" in CLAUDE.md). */
    public function cutoff(int $day): static
    {
        return $this->state(fn () => ['cutoff' => $day]);
    }
}
