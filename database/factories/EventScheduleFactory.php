<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSchedule>
 */
class EventScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'title' => fake()->randomElement(['Bodhon', 'Ashtami Anjali', 'Sandhi Puja', 'Sindur Khela', 'Bhog Distribution']),
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+2 months'),
            'description' => fake()->optional()->sentence(),
            'sort_order' => 0,
        ];
    }
}
