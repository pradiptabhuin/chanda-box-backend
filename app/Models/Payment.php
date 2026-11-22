<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransferStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment attempt: a Razorpay order plus its single-use UPI QR code.
 * `amount` is the contribution (what the organiser gets); the payer is charged `charged_amount`
 * (amount + platform_fee). After capture `amount` is transferred to the organiser via Route.
 */
#[Fillable([
    'event_id', 'user_id', 'on_behalf_of', 'amount', 'platform_fee', 'charged_amount',
    'razorpay_linked_account_id', 'razorpay_transfer_id', 'transfer_status', 'transfer_error', 'transferred_at', 'currency', 'razorpay_order_id', 'razorpay_qr_id',
    'razorpay_payment_id', 'razorpay_signature', 'qr_payload', 'qr_image_url',
    'method', 'status', 'expires_at', 'paid_at', 'failure_reason',
])]
#[Hidden(['razorpay_signature'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'charged_amount' => 'decimal:2',
            'transfer_status' => TransferStatus::class,
            'transferred_at' => 'datetime',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The payer.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The member the owner is paying for (null when paying for themselves).
     *
     * @return BelongsTo<User, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of');
    }

    /**
     * The user the contribution counts for.
     */
    public function contributorId(): int
    {
        return $this->on_behalf_of ?? $this->user_id;
    }

    /**
     * @return HasOne<Contribution, $this>
     */
    public function contribution(): HasOne
    {
        return $this->hasOne(Contribution::class);
    }

    /**
     * What the payer was asked for (older rows predate the platform fee).
     */
    public function chargedAmount(): float
    {
        return (float) ($this->charged_amount ?? $this->amount);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }
}
