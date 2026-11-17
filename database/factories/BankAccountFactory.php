<?php

namespace Database\Factories;

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
            'upi_id' => null,
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => ['is_primary' => true]);
    }
}
