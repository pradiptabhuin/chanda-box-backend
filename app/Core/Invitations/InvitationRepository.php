<?php

namespace App\Core\Invitations;

use App\Enums\MemberStatus;
use App\Models\EventMember;
use App\Models\User;
use App\Notifications\InvitationAccepted;
use App\Notifications\InvitationReceived;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InvitationRepository implements InvitationInterface
{
    public function pendingFor(User $user): Collection
    {
        return $user->pendingInvitations()
            ->whereHas('event')
            ->with([
                'inviter:id,first_name,middle_name,last_name',
                'event' => fn ($q) => $q->withCount('joinedMembers as member_count'),
            ])
            ->latest('invited_at')
            ->latest('id')
            ->get();
    }

    public function accept(User $user, int $invitationId): EventMember
    {
        $invitation = $this->respond($user, $invitationId, MemberStatus::Joined);

        $invitation->event->owner->notify(new InvitationAccepted($invitation, $user));

        return $invitation;
    }

    public function decline(User $user, int $invitationId): EventMember
    {
        return $this->respond($user, $invitationId, MemberStatus::Declined);
    }

    public function claimForNewUser(User $user): void
    {
        $query = EventMember::query()
            ->whereNull('user_id')
            ->where('phone', $user->phone)
            ->where('status', MemberStatus::Invited);

        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        EventMember::whereKey($ids)->update(['user_id' => $user->id]);

        EventMember::whereKey($ids)
            ->with(['event', 'inviter:id,first_name,middle_name,last_name'])
            ->get()
            ->each(fn (EventMember $invitation) => $user->notify(new InvitationReceived($invitation)));
    }

    /**
     * Locks the row so a double tap can't answer the same invitation twice.
     */
    private function respond(User $user, int $invitationId, MemberStatus $status): EventMember
    {
        return DB::transaction(function () use ($user, $invitationId, $status) {
            /** @var EventMember $invitation */
            $invitation = $user->pendingInvitations()
                ->whereHas('event')
                ->whereKey($invitationId)
                ->lockForUpdate()
                ->firstOrFail();

            $invitation->update([
                'status' => $status,
                'responded_at' => now(),
                'added_at' => $status === MemberStatus::Joined ? now() : null,
            ]);

            return $invitation;
        });
    }
}
