<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A payout account per user. The demo user's is already active on Route, so with
 * RAZORPAY_ROUTE_ENABLED=true their events take online payments right away.
 */
class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::orderBy('id')->each(fn (User $user) => BankAccount::factory()
            ->for($user)
            ->when($user->phone === UserSeeder::DEMO_PHONE, fn ($factory) => $factory->active())
            ->create(['holder_name' => $user->name]));
    }
}
