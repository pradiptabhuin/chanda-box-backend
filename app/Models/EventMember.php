<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use Database\Factories\EventMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership and invitation in one row, keyed by (event, phone). `user_id` stays
 * null until the invited phone registers. The row id is the invitation id.
 */
#[Fillable([
    'event_id', 'user_id', 'phone', 'role', 'status', 'invited_by',
    'invited_at', 'responded_at', 'added_at', 'left_at',
])]
class EventMember extends Model
{
    /** @use HasFactory<EventMemberFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'status' => MemberStatus::class,
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
            'added_at' => 'datetime',
            'left_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isOwner(): bool
    {
        return $this->role === MemberRole::Owner;
    }
}
