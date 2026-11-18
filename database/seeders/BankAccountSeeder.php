<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One primary bank account per user (withdrawals are paid out to it).
 */
class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::orderBy('id')->each(fn (User $user) => BankAccount::factory()->for($user)->primary()->create([
            'holder_name' => $user->name,
            'upi_id' => strtolower(str_replace(' ', '.', $user->name)).'@okaxis',
        ]));
    }
}
