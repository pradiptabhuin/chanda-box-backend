<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo data: 10 records per table, linked the way the app creates them.
     * Log in with 9876543210 / password (every seeded user's password is "password").
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DeviceTokenSeeder::class,
            BankAccountSeeder::class,
            EventSeeder::class,
            EventScheduleSeeder::class,
            EventMemberSeeder::class,
            PaymentSeeder::class,
            ContributionSeeder::class,
            NotificationSeeder::class,
            WebhookEventSeeder::class,
        ]);
    }
}
