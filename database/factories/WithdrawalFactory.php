<?php

namespace Database\Factories;

use App\Enums\WithdrawalStatus;
use App\Models\BankAccount;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
{
    /**
     * Define the model's default state: a pending withdrawal to the user's own bank account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'user_id' => fn (array $attributes) => BankAccount::find($attributes['bank_account_id'])->user_id,
            'amount' => fake()->randomElement([500, 1000, 2500, 5000]),
            'status' => WithdrawalStatus::Pending,
            'requested_at' => now(),
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WithdrawalStatus::Processed,
            'razorpay_payout_id' => 'pout_'.fake()->unique()->bothify('??????????????'),
            'utr' => fake()->numerify('############'),
            'processed_at' => now(),
        ]);
    }
}
