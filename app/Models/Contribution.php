<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\ContributionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for an event: a captured online payment or an approved cash request.
 * `user_id` is the member it counts for; `paid_by` is set when someone else (the owner) paid.
 */
#[Fillable([
    'event_id', 'user_id', 'paid_by', 'payment_id', 'cash_request_id',
    'amount', 'method', 'payment_ref', 'note', 'paid_at',
])]
class Contribution extends Model
{
    /** @use HasFactory<ContributionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
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
     * Who handed over the money, when it wasn't the member.
     *
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return BelongsTo<CashRequest, $this>
     */
    public function cashRequest(): BelongsTo
    {
        return $this->belongsTo(CashRequest::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
