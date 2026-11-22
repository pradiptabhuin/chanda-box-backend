<?php

namespace App\Core\Invitations;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Http\Requests\Auth\PhoneRequest;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use App\Notifications\InvitationAccepted;
use App\Notifications\InvitationReceived;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InvitationRepository implements InvitationInterface
{
    private const VALID_PHONE = '/^[6-9]\d{9}$/';

    public function invite(Event $event, User $inviter, array $phones): array
    {
        $skipped = [];
        $valid = [];

        foreach ($phones as $raw) {
            $phone = PhoneRequest::normalizePhone($raw);

            if (! preg_match(self::VALID_PHONE, $phone)) {
                $skipped[] = $raw;
            } elseif (in_array($phone, $valid, true)) {
                $skipped[] = $phone;
            } else {
                $valid[] = $phone;
            }
        }

        $invitations = DB::transaction(function () use ($event, $inviter, $valid, &$skipped) {
            $existing = $event->members()->whereIn('phone', $valid)->lockForUpdate()->get()->keyBy('phone');
            $users = User::whereIn('phone', $valid)->pluck('id', 'phone');
            $invitations = [];

            foreach ($valid as $phone) {
                /** @var EventMember|null $member */
                $member = $existing->get($phone);

                if ($member !== null && in_array($member->status, [MemberStatus::Joined, MemberStatus::Invited], true)) {
                    $skipped[] = $phone;

                    continue;
                }

                $attributes = [
                    'user_id' => $users->get($phone),
                    'role' => MemberRole::Member,
                    'status' => MemberStatus::Invited,
                    'invited_by' => $inviter->id,
                    'invited_at' => now(),
                    'responded_at' => null,
                    'added_at' => null,
                    'left_at' => null,
                ];

                $invitations[] = $member !== null
                    ? tap($member)->update($attributes)
                    : $event->members()->create(['phone' => $phone, ...$attributes]);
            }

            return $invitations;
        });

        foreach ($invitations as $invitation) {
            if ($invitation->user_id === null) {
                continue;
            }

            $invitation->setRelation('event', $event)->setRelation('inviter', $inviter);
            $invitation->user->notify(new InvitationReceived($invitation));
        }

        return ['invited' => count($invitations), 'skipped' => $skipped];
    }

    public function pendingFor(User $user): Collection
    {
        return $user->pendingInvitations()
            ->whereHas('event')
            ->with([
                'inviter:'.User::SUMMARY_COLUMNS,
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
            ->with(['event', 'inviter:'.User::SUMMARY_COLUMNS])
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
