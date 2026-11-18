<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 10 users, all with the password "password". 9876543210 is the demo account.
 */
class UserSeeder extends Seeder
{
    public const DEMO_PHONE = '9876543210';

    /** @var array<string, string> phone => name */
    public const USERS = [
        self::DEMO_PHONE => 'Demo User',
        '9800000002' => 'Arijit Sen',
        '9800000003' => 'Ananya Roy',
        '9800000004' => 'Sourav Das',
        '9800000005' => 'Priya Ghosh',
        '9800000006' => 'Rahul Banerjee',
        '9800000007' => 'Moumita Saha',
        '9800000008' => 'Debashis Paul',
        '9800000009' => 'Rina Mukherjee',
        '9800000010' => 'Subhajit Dey',
    ];

    public function run(): void
    {
        foreach (self::USERS as $phone => $name) {
            User::factory()->create([
                'name' => $name,
                'phone' => $phone,
                'email' => null,
                'email_verified_at' => null,
            ]);
        }
    }
}
