<?php

namespace Database\Seeders;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Contribution;
use App\Models\EventMember;
use App\Notifications\InvitationAccepted;
use App\Notifications\InvitationReceived;
use App\Notifications\PaymentReceived;
use Carbon\CarbonInterface;
use Database\Seeders\Concerns\FindsSeedRecords;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * 10 inbox notifications, sent through the real notification classes so they match
 * what the app receives: 3 pending invitations (unread), 6 accepted invitations (read)
 * and the demo user's latest payment received (unread).
 */
class NotificationSeeder extends Seeder
{
    use FindsSeedRecords;

    public function run(): void
    {
        EventMember::with(['event', 'inviter', 'user'])
            ->where('role', MemberRole::Member)
            ->whereIn('status', [MemberStatus::Invited, MemberStatus::Joined])
            ->orderBy('id')
            ->each(function (EventMember $membership) {
                if ($membership->status === MemberStatus::Invited) {
                    $this->sendAt($membership->invited_at, fn () => $membership->user->notify(new InvitationReceived($membership)));

                    return;
                }

                $owner = $membership->event->owner;
                $this->sendAt($membership->added_at, function () use ($owner, $membership) {
                    $owner->notify(new InvitationAccepted($membership, $membership->user));
                    $owner->unreadNotifications()->where('created_at', now())->update(['read_at' => now()]);
                });
            });

        $demo = $this->user(UserSeeder::DEMO_PHONE);
        $latestPayment = Contribution::with(['event', 'user'])
            ->whereRelation('event', 'owner_id', $demo->id)
            ->where('user_id', '!=', $demo->id)
            ->latest('paid_at')
            ->firstOrFail();

        $this->sendAt($latestPayment->paid_at, fn () => $demo->notify(new PaymentReceived($latestPayment)));
    }

    /**
     * Notifications get the time of what they announce, so the inbox has a realistic order.
     */
    private function sendAt(CarbonInterface $at, callable $send): void
    {
        Date::setTestNow($at);

        try {
            $send();
        } finally {
            Date::setTestNow();
        }
    }
}
