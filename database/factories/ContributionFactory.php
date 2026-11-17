<?php

namespace Database\Factories;

use App\Models\Contribution;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contribution>
 */
class ContributionFactory extends Factory
{
    /**
     * Define the model's default state. Event, payer and amount are taken from a paid payment.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->paid(),
            'event_id' => fn (array $attributes) => Payment::find($attributes['payment_id'])->event_id,
            'user_id' => fn (array $attributes) => Payment::find($attributes['payment_id'])->user_id,
            'amount' => fn (array $attributes) => Payment::find($attributes['payment_id'])->amount,
            'method' => fn (array $attributes) => Payment::find($attributes['payment_id'])->method,
            'payment_ref' => fn (array $attributes) => Payment::find($attributes['payment_id'])->razorpay_payment_id,
            'note' => null,
            'paid_at' => now(),
        ];
    }
}
