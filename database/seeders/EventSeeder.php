<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use Database\Seeders\Concerns\FindsSeedRecords;
use Illuminate\Database\Seeder;

/**
 * 10 events: 3 organised by the demo user, 7 by others. Two are already over.
 */
class EventSeeder extends Seeder
{
    use FindsSeedRecords;

    /**
     * @var list<array{name: string, owner: string, location: string, proposed: int, target: int|null, starts_in_days: int, days: int}>
     */
    public const EVENTS = [
        ['name' => 'Sarbojanin Durga Puja', 'owner' => UserSeeder::DEMO_PHONE, 'location' => 'Netaji Park, Salt Lake', 'proposed' => 1001, 'target' => 150000, 'starts_in_days' => 12, 'days' => 5],
        ['name' => 'Saraswati Puja', 'owner' => UserSeeder::DEMO_PHONE, 'location' => 'Block C Community Hall', 'proposed' => 501, 'target' => 25000, 'starts_in_days' => 45, 'days' => 1],
        ['name' => 'Para Picnic 2026', 'owner' => UserSeeder::DEMO_PHONE, 'location' => 'Bakkhali Beach', 'proposed' => 1500, 'target' => null, 'starts_in_days' => -30, 'days' => 1],
        ['name' => 'Kali Puja', 'owner' => '9800000003', 'location' => 'Lake Town Kali Mandir', 'proposed' => 701, 'target' => 80000, 'starts_in_days' => 8, 'days' => 2],
        ['name' => 'Jagaddhatri Puja', 'owner' => '9800000004', 'location' => 'Chandannagar Strand', 'proposed' => 1001, 'target' => 100000, 'starts_in_days' => 20, 'days' => 4],
        ['name' => 'Lakshmi Puja', 'owner' => '9800000005', 'location' => 'Behala Chowrasta', 'proposed' => 301, 'target' => 20000, 'starts_in_days' => 3, 'days' => 1],
        ['name' => 'Rath Yatra Utsav', 'owner' => '9800000006', 'location' => 'ISKCON Road, Mayapur', 'proposed' => 501, 'target' => 60000, 'starts_in_days' => 60, 'days' => 2],
        ['name' => 'Holi Milan', 'owner' => '9800000007', 'location' => 'Dum Dum Park', 'proposed' => 251, 'target' => null, 'starts_in_days' => 90, 'days' => 1],
        ['name' => 'Poila Baisakh Mela', 'owner' => '9800000008', 'location' => 'Rabindra Sarobar', 'proposed' => 501, 'target' => 40000, 'starts_in_days' => 120, 'days' => 2],
        ['name' => 'Christmas Carnival', 'owner' => '9800000009', 'location' => 'Park Street', 'proposed' => 1001, 'target' => 75000, 'starts_in_days' => -60, 'days' => 3],
    ];

    public function run(): void
    {
        foreach (self::EVENTS as $event) {
            $start = now()->startOfDay()->addDays($event['starts_in_days'])->setTime(9, 0);

            Event::factory()->for($this->user($event['owner']), 'owner')->create([
                'name' => $event['name'],
                'description' => "Annual {$event['name']} of our para. Contribute and track every rupee transparently.",
                'location' => $event['location'],
                'proposed_amount' => $event['proposed'],
                'target_amount' => $event['target'],
                'start_date' => $start,
                'end_date' => $start->copy()->addDays($event['days'] - 1)->setTime(22, 0),
                'status' => $event['starts_in_days'] < 0 ? EventStatus::Closed : EventStatus::Active,
            ]);
        }
    }
}
