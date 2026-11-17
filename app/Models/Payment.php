<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment attempt: a Razorpay order plus its single-use UPI QR code.
 */
#[Fillable([
    'event_id', 'user_id', 'amount', 'currency', 'razorpay_order_id', 'razorpay_qr_id',
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
     * @return HasOne<Contribution, $this>
     */
    public function contribution(): HasOne
    {
        return $this->hasOne(Contribution::class);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }
}
