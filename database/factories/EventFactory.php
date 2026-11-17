<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+2 months');

        return [
            'owner_id' => User::factory(),
            'name' => fake()->randomElement(['Durga Puja', 'Kali Puja', 'Saraswati Puja', 'Para Picnic']).' '.fake()->year(),
            'description' => fake()->paragraph(),
            'location' => fake()->streetAddress(),
            'proposed_amount' => fake()->randomElement([501, 701, 1001, 2001]),
            'target_amount' => fake()->optional()->randomElement([50000, 100000, 150000]),
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+4 days'),
            'status' => EventStatus::Active,
        ];
    }

    /**
     * An event that has already ended.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => now()->subMonth(),
            'end_date' => now()->subMonth()->addDays(4),
            'status' => EventStatus::Closed,
        ]);
    }
}
