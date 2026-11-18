<?php

namespace Database\Seeders;

use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\EventMember;
use Database\Seeders\Concerns\FindsSeedRecords;
use Illuminate\Database\Seeder;

/**
 * Every event gets its owner row (the app counts the owner as a member), plus 10
 * memberships and invitations: the demo user has joined 2 events and has 2 pending invites.
 */
class EventMemberSeeder extends Seeder
{
    use FindsSeedRecords;

    /**
     * @var list<array{event: string, phone: string, status: MemberStatus}>
     */
    public const MEMBERSHIPS = [
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000002', 'status' => MemberStatus::Joined],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000003', 'status' => MemberStatus::Joined],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000004', 'status' => MemberStatus::Joined],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000006', 'status' => MemberStatus::Invited],
        ['event' => 'Saraswati Puja', 'phone' => '9800000005', 'status' => MemberStatus::Joined],
        ['event' => 'Saraswati Puja', 'phone' => '9800000007', 'status' => MemberStatus::Declined],
        ['event' => 'Kali Puja', 'phone' => UserSeeder::DEMO_PHONE, 'status' => MemberStatus::Joined],
        ['event' => 'Jagaddhatri Puja', 'phone' => UserSeeder::DEMO_PHONE, 'status' => MemberStatus::Joined],
        ['event' => 'Lakshmi Puja', 'phone' => UserSeeder::DEMO_PHONE, 'status' => MemberStatus::Invited],
        ['event' => 'Rath Yatra Utsav', 'phone' => UserSeeder::DEMO_PHONE, 'status' => MemberStatus::Invited],
    ];

    public function run(): void
    {
        Event::with('owner')->each(fn (Event $event) => EventMember::factory()->owner()->for($event)->create([
            'user_id' => $event->owner_id,
            'phone' => $event->owner->phone,
            'added_at' => $event->created_at,
        ]));

        foreach (self::MEMBERSHIPS as $membership) {
            $event = $this->event($membership['event']);
            $user = $this->user($membership['phone']);
            $factory = match ($membership['status']) {
                MemberStatus::Invited => EventMember::factory()->invited(),
                MemberStatus::Declined => EventMember::factory()->declined(),
                default => EventMember::factory(),
            };

            $factory->for($event)->create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'invited_by' => $event->owner_id,
            ]);
        }
    }
}
