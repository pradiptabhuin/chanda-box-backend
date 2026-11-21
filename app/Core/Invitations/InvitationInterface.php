<?php

namespace App\Core\Invitations;

use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sending and answering invitations. An invitation is an `event_members` row with status `invited`.
 */
interface InvitationInterface
{
    /**
     * Invite phone numbers to the event and notify the ones already registered.
     *
     * Skips invalid numbers, duplicates, the owner, joined members and pending invitations;
     * someone who declined or was removed is invited again.
     *
     * @param  list<string>  $phones  Raw input; normalised to 10 digits here.
     * @return array{invited: int, skipped: list<string>}
     */
    public function invite(Event $event, User $inviter, array $phones): array;

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
