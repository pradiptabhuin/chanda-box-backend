<?php

namespace App\Policies;

use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\User;

/**
 * Owner: everything. Joined member: view the event, its members and contributions.
 * Pending invitee: view the event only (to decide whether to join).
 */
class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event)
            || $this->hasMembership($user, $event, [MemberStatus::Joined, MemberStatus::Invited]);
    }

    /**
     * Members list and contributions are only for people inside the event.
     */
    public function viewActivity(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event) || $this->hasMembership($user, $event, [MemberStatus::Joined]);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event);
    }

    private function isOwner(User $user, Event $event): bool
    {
        return $event->owner_id === $user->id;
    }

    /**
     * @param  list<MemberStatus>  $statuses
     */
    private function hasMembership(User $user, Event $event, array $statuses): bool
    {
        return $event->members()->where('user_id', $user->id)->whereIn('status', $statuses)->exists();
    }
}
