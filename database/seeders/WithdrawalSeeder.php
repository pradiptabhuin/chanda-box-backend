<?php

namespace Database\Seeders;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use Database\Seeders\Concerns\FindsSeedRecords;
use Illuminate\Database\Seeder;

/**
 * 10 withdrawals by organisers who received money, kept within their wallet balance.
 */
class WithdrawalSeeder extends Seeder
{
    use FindsSeedRecords;

    /**
     * @var list<array{phone: string, amount: int, status: WithdrawalStatus, days_ago: int}>
     */
    public const WITHDRAWALS = [
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 1000, 'status' => WithdrawalStatus::Processed, 'days_ago' => 6],
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 500, 'status' => WithdrawalStatus::Processed, 'days_ago' => 5],
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 2000, 'status' => WithdrawalStatus::Processed, 'days_ago' => 4],
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 300, 'status' => WithdrawalStatus::Failed, 'days_ago' => 3],
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 250, 'status' => WithdrawalStatus::Processed, 'days_ago' => 2],
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 100, 'status' => WithdrawalStatus::Processed, 'days_ago' => 1],
        ['phone' => UserSeeder::DEMO_PHONE, 'amount' => 1500, 'status' => WithdrawalStatus::Pending, 'days_ago' => 0],
        ['phone' => '9800000003', 'amount' => 500, 'status' => WithdrawalStatus::Processed, 'days_ago' => 1],
        ['phone' => '9800000003', 'amount' => 200, 'status' => WithdrawalStatus::Pending, 'days_ago' => 0],
        ['phone' => '9800000004', 'amount' => 500, 'status' => WithdrawalStatus::Processed, 'days_ago' => 1],
    ];

    public function run(): void
    {
        foreach (self::WITHDRAWALS as $withdrawal) {
            $user = $this->user($withdrawal['phone']);
            $requestedAt = now()->subDays($withdrawal['days_ago'])->setTime(16, 0);
            $factory = Withdrawal::factory();

            if ($withdrawal['status'] === WithdrawalStatus::Processed) {
                $factory = $factory->processed();
            }

            $factory->create([
                'user_id' => $user->id,
                'bank_account_id' => $user->bankAccounts()->where('is_primary', true)->value('id'),
                'amount' => $withdrawal['amount'],
                'status' => $withdrawal['status'],
                'failure_reason' => $withdrawal['status'] === WithdrawalStatus::Failed ? 'Beneficiary bank was unavailable.' : null,
                'requested_at' => $requestedAt,
                'processed_at' => $withdrawal['status'] === WithdrawalStatus::Pending ? null : $requestedAt->copy()->addHour(),
                'created_at' => $requestedAt,
                'updated_at' => $requestedAt,
            ]);
        }
    }
}
