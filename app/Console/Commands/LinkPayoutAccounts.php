<?php

namespace App\Console\Commands;

use App\Core\Payouts\PayoutAccountInterface;
use App\Enums\RouteStatus;
use App\Models\BankAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payouts:link')]
#[Description('Register saved payout accounts with Razorpay Route (run once after enabling RAZORPAY_ROUTE_ENABLED)')]
class LinkPayoutAccounts extends Command
{
    public function handle(PayoutAccountInterface $payoutAccounts): int
    {
        if (! config('payments.route_enabled')) {
            $this->error('Set RAZORPAY_ROUTE_ENABLED=true first.');

            return self::FAILURE;
        }

        BankAccount::query()
            ->whereIn('route_status', [RouteStatus::NotLinked, RouteStatus::Failed])
            ->with('user')
            ->orderBy('id')
            ->each(function (BankAccount $account) use ($payoutAccounts) {
                $status = $payoutAccounts->link($account)->route_status;
                $this->line("{$account->user->name}: {$status->value}");
            });

        return self::SUCCESS;
    }
}
