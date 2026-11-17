<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state: an open (unpaid) order.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomElement([501, 701, 1001, 2001]);

        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'amount' => $amount,
            'currency' => 'INR',
            'razorpay_order_id' => 'order_'.fake()->unique()->bothify('??????????????'),
            'razorpay_qr_id' => 'qr_'.fake()->unique()->bothify('??????????????'),
            'qr_payload' => "upi://pay?pa=chandabox@razorpay&am={$amount}.00&cu=INR",
            'status' => PaymentStatus::Created,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function paid(PaymentMethod $method = PaymentMethod::UpiQr): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'method' => $method,
            'razorpay_payment_id' => 'pay_'.fake()->unique()->bothify('??????????????'),
            'paid_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
