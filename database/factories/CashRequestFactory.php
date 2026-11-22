<?php

namespace Database\Factories;

use App\Enums\CashRequestStatus;
use App\Models\CashRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRequest>
 */
class CashRequestFactory extends Factory
{
    /**
     * Define the model's default state: a member's own request awaiting the owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'requested_by' => fn (array $attributes) => $attributes['user_id'],
            'amount' => fake()->randomElement([501, 701, 1001, 2001]),
            'note' => null,
            'status' => CashRequestStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CashRequestStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CashRequestStatus::Rejected,
            'reviewed_at' => now(),
            'rejection_reason' => 'Cash not received.',
        ]);
    }
}
