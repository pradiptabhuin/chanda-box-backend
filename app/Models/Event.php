<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\MemberStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A chanda (collection drive), e.g. "Sarbojanin Durga Puja".
 */
#[Fillable([
    'owner_id', 'name', 'description', 'location', 'proposed_amount',
    'target_amount', 'start_date', 'end_date', 'status',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposed_amount' => 'decimal:2',
            'target_amount' => 'decimal:2',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Programme items (the API's `curriculum`), in display order.
     *
     * @return HasMany<EventSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(EventSchedule::class)->orderBy('sort_order')->orderBy('scheduled_at');
    }

    /**
     * All member rows: owner, joined members, pending and declined invitations.
     *
     * @return HasMany<EventMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(EventMember::class);
    }

    /**
     * @return HasMany<EventMember, $this>
     */
    public function joinedMembers(): HasMany
    {
        return $this->members()->where('status', MemberStatus::Joined);
    }

    /**
     * @return HasMany<EventMember, $this>
     */
    public function pendingInvitations(): HasMany
    {
        return $this->members()->where('status', MemberStatus::Invited);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Contribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }
}
