<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\ContributionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A successful payment to an event (one per captured payment).
 */
#[Fillable(['event_id', 'user_id', 'payment_id', 'amount', 'method', 'payment_ref', 'note', 'paid_at'])]
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The credit this contribution put in the event owner's wallet.
     *
     * @return MorphOne<WalletTransaction, $this>
     */
    public function walletTransaction(): MorphOne
    {
        return $this->morphOne(WalletTransaction::class, 'source');
    }
}
