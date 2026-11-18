<?php

namespace App\Core\Invitations;

use App\Models\EventMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Invitations the user has received. An invitation is an `event_members` row with status `invited`.
 */
interface InvitationInterface
{
    /**
     * Pending invitations, newest first, with the event and inviter loaded.
     *
     * @return Collection<int, EventMember>
     */
    public function pendingFor(User $user): Collection;

    /**
     * Join the event and notify its owner. 404 if the invitation isn't the user's or was already answered.
     */
    public function accept(User $user, int $invitationId): EventMember;

    /**
     * 404 if the invitation isn't the user's or was already answered.
     */
    public function decline(User $user, int $invitationId): EventMember;

    /**
     * Attach invitations sent to the user's phone before they registered, and notify them.
     */
    public function claimForNewUser(User $user): void;
}
