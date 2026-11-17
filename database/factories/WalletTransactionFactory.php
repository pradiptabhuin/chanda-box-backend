<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Contribution;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    /**
     * Define the model's default state: a successful credit with its own source id.
     * Prefer {@see self::forContribution()} when the source row matters.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => TransactionType::Credit,
            'status' => TransactionStatus::Success,
            'amount' => fake()->randomElement([501, 701, 1001, 2001]),
            'title' => fake()->name(),
            'subtitle' => 'Durga Puja',
            'source_type' => 'contribution',
            'source_id' => fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }

    /**
     * The credit a contribution puts into its event owner's wallet.
     */
    public function forContribution(Contribution $contribution): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $contribution->event->owner_id,
            'type' => TransactionType::Credit,
            'status' => TransactionStatus::Success,
            'amount' => $contribution->amount,
            'title' => $contribution->user->name,
            'subtitle' => $contribution->event->name,
            'source_type' => 'contribution',
            'source_id' => $contribution->id,
        ]);
    }

    public function debit(TransactionStatus $status = TransactionStatus::Pending): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TransactionType::Debit,
            'status' => $status,
            'source_type' => 'withdrawal',
        ]);
    }
}
