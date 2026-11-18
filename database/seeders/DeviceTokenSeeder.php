<?php

namespace Database\Seeders;

use App\Enums\DevicePlatform;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One device per user.
 */
class DeviceTokenSeeder extends Seeder
{
    public function run(): void
    {
        User::orderBy('id')->each(fn (User $user, int $index) => DeviceToken::factory()->for($user)->create([
            'platform' => $index % 4 === 3 ? DevicePlatform::Ios : DevicePlatform::Android,
        ]));
    }
}
