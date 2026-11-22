<?php

namespace App\Models;

use App\Enums\RouteStatus;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user's payout account: the bank Razorpay Route pays their events' online contributions into.
 * One per user. The account number and PAN are stored encrypted and never leave the API in full.
 */
#[Fillable([
    'user_id', 'holder_name', 'account_number', 'ifsc', 'bank_name',
    'email', 'pan', 'street', 'city', 'state', 'postal_code',
    'razorpay_account_id', 'razorpay_product_id', 'route_status', 'route_status_reason',
])]
#[Hidden(['account_number', 'pan', 'razorpay_account_id', 'razorpay_product_id'])]
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
            'pan' => 'encrypted',
            'route_status' => RouteStatus::class,
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

    public function isActive(): bool
    {
        return $this->route_status === RouteStatus::Active;
    }
}
