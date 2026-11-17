<?php

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'razorpay',
            'event_id' => 'evt_'.fake()->unique()->bothify('??????????????'),
            'event_type' => 'payment.captured',
            'payload' => ['event' => 'payment.captured', 'payload' => []],
            'processed_at' => null,
        ];
    }
}
