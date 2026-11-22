<?php

namespace Database\Factories;

use App\Enums\RouteStatus;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'holder_name' => fake()->name(),
            'account_number' => fake()->numerify('############'),
            'ifsc' => fake()->randomElement(['HDFC', 'SBIN', 'ICIC', 'UTIB']).'0'.fake()->numerify('######'),
            'bank_name' => fake()->randomElement(['HDFC Bank', 'State Bank of India', 'ICICI Bank', 'Axis Bank']),
            'email' => fake()->safeEmail(),
            'street' => fake()->streetAddress(),
            'city' => 'Kolkata',
            'state' => 'West Bengal',
            'postal_code' => fake()->numerify('700###'),
            'route_status' => RouteStatus::NotLinked,
        ];
    }

    /**
     * Linked with Razorpay Route and able to receive online contributions.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'razorpay_account_id' => 'acc_'.fake()->unique()->bothify('??????????????'),
            'razorpay_product_id' => 'acc_prd_'.fake()->unique()->bothify('????????????'),
            'route_status' => RouteStatus::Active,
        ]);
    }
}
