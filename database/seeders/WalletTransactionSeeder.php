<?php

namespace Database\Seeders;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Models\Contribution;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Database\Seeder;

/**
 * The wallet ledger is derived from the other tables, as in production: a credit to the
 * event owner for every contribution and a debit for every withdrawal.
 */
class WalletTransactionSeeder extends Seeder
{
    public function run(): void
    {
        Contribution::with(['event', 'user'])->orderBy('id')->each(
            fn (Contribution $contribution) => WalletTransaction::factory()->forContribution($contribution)->create([
                'created_at' => $contribution->paid_at,
                'updated_at' => $contribution->paid_at,
            ])
        );

        Withdrawal::with('bankAccount')->orderBy('id')->each(fn (Withdrawal $withdrawal) => WalletTransaction::factory()->create([
            'user_id' => $withdrawal->user_id,
            'type' => TransactionType::Debit,
            'status' => match ($withdrawal->status) {
                WithdrawalStatus::Processed => TransactionStatus::Success,
                WithdrawalStatus::Failed, WithdrawalStatus::Reversed => TransactionStatus::Failed,
                default => TransactionStatus::Pending,
            },
            'amount' => $withdrawal->amount,
            'title' => 'Withdrawal to bank',
            'subtitle' => $withdrawal->bankAccount->bank_name,
            'source_type' => 'withdrawal',
            'source_id' => $withdrawal->id,
            'created_at' => $withdrawal->requested_at,
            'updated_at' => $withdrawal->requested_at,
        ]));
    }
}
