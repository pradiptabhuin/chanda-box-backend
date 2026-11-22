<?php

namespace App\Models;

use App\Enums\CashRequestStatus;
use Database\Factories\CashRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cash handed to the event owner. `user_id` is the member it counts for, `requested_by` who created it.
 * Becomes a contribution once approved.
 */
#[Fillable([
    'event_id', 'user_id', 'requested_by', 'amount', 'note', 'status',
    'reviewed_by', 'reviewed_at', 'rejection_reason',
])]
class CashRequest extends Model
{
    /** @use HasFactory<CashRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => CashRequestStatus::class,
            'reviewed_at' => 'datetime',
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
     * The member the cash counts for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasOne<Contribution, $this>
     */
    public function contribution(): HasOne
    {
        return $this->hasOne(Contribution::class);
    }

    public function isPending(): bool
    {
        return $this->status === CashRequestStatus::Pending;
    }
}
