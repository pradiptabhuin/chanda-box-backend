<?php

namespace App\Core\Payouts;

use App\Models\BankAccount;
use App\Models\User;

/**
 * @phpstan-type PayoutAccountData array{holder_name: string, account_number: string, ifsc: string, bank_name: string, email?: string|null, pan?: string|null, street: string, city: string, state: string, postal_code: string}
 */
interface PayoutAccountInterface
{
    /**
     * The user's payout account, with its Route status refreshed from Razorpay while it's under review.
     */
    public function find(User $user): ?BankAccount;

    /**
     * Saves (or replaces) the payout account and, when Route is on, registers it with Razorpay.
     * Razorpay errors don't fail the save: they're recorded as the account's status and reason.
     *
     * @param  PayoutAccountData  $data
     */
    public function save(User $user, array $data): BankAccount;

    /**
     * Runs the Razorpay linking again (after a failure, or once Route has been switched on).
     */
    public function link(BankAccount $account): BankAccount;

    public function remove(User $user): void;

    /**
     * Whether online contributions to this organiser's events can be paid out to them.
     */
    public function acceptsOnlinePayments(User $organiser): bool;
}
