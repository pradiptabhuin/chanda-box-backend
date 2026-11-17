<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Database\Factories\WithdrawalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A payout request from the wallet to a bank account (RazorpayX Payouts).
 */
#[Fillable([
    'user_id', 'bank_account_id', 'amount', 'status', 'razorpay_payout_id',
    'utr', 'failure_reason', 'requested_at', 'processed_at',
])]
class Withdrawal extends Model
{
    /** @use HasFactory<WithdrawalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => WithdrawalStatus::class,
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Includes soft-deleted accounts so old withdrawals still show their bank.
     *
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    /**
     * The debit this withdrawal made in the user's wallet.
     *
     * @return MorphOne<WalletTransaction, $this>
     */
    public function walletTransaction(): MorphOne
    {
        return $this->morphOne(WalletTransaction::class, 'source');
    }
}
