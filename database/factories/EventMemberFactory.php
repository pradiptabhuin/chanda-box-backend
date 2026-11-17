<?php

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMember>
 */
class EventMemberFactory extends Factory
{
    /**
     * Define the model's default state: a joined member.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'phone' => fn (array $attributes) => User::find($attributes['user_id'])?->phone ?? fake()->unique()->numerify('9#########'),
            'role' => MemberRole::Member,
            'status' => MemberStatus::Joined,
            'invited_by' => null,
            'invited_at' => now()->subDays(2),
            'responded_at' => now()->subDay(),
            'added_at' => now()->subDay(),
        ];
    }

    /**
     * The event owner's own member row.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MemberRole::Owner,
            'status' => MemberStatus::Joined,
            'invited_at' => null,
            'responded_at' => null,
            'added_at' => now(),
        ]);
    }

    /**
     * A pending invitation.
     */
    public function invited(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MemberStatus::Invited,
            'responded_at' => null,
            'added_at' => null,
        ]);
    }

    /**
     * An invitation sent to a phone number that has not registered yet.
     */
    public function unregistered(): static
    {
        return $this->invited()->state(fn (array $attributes) => [
            'user_id' => null,
            'phone' => fake()->unique()->numerify('9#########'),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MemberStatus::Declined,
            'added_at' => null,
        ]);
    }
}
