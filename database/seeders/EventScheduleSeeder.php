<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventSchedule;
use Illuminate\Database\Seeder;

/**
 * One programme item (the API's `curriculum`) per event, on its first evening.
 */
class EventScheduleSeeder extends Seeder
{
    private const TITLES = ['Bodhon', 'Pushpanjali', 'Sandhya Aarti', 'Bhog Distribution', 'Cultural Programme'];

    public function run(): void
    {
        Event::orderBy('id')->each(fn (Event $event, int $index) => EventSchedule::factory()->for($event)->create([
            'title' => self::TITLES[$index % count(self::TITLES)],
            'scheduled_at' => $event->start_date->copy()->setTime(18, 30),
            'description' => 'Everyone is welcome.',
            'sort_order' => 0,
        ]));
    }
}
