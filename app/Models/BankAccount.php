<?php

namespace App\Models;

use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's payout destination. The account number is stored encrypted and must
 * only ever leave the API masked, via the `masked_account_number` attribute.
 */
#[Fillable([
    'user_id', 'holder_name', 'account_number', 'ifsc', 'bank_name', 'upi_id',
    'is_primary', 'razorpay_contact_id', 'razorpay_fund_account_id',
])]
#[Hidden(['account_number', 'razorpay_contact_id', 'razorpay_fund_account_id'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Keep `account_last4` in sync whenever the account number is set.
     *
     * @return Attribute<string, string>
     */
    protected function accountNumber(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'account_number' => encrypt($value, false),
                'account_last4' => substr($value, -4),
            ],
        );
    }

    /**
     * e.g. `XXXXXXXX4321`, as returned by the API.
     *
     * @return Attribute<string, never>
     */
    protected function maskedAccountNumber(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => 'XXXXXXXX'.$attributes['account_last4'],
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Withdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
}
