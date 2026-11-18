<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_ten_records_per_table(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            'users', 'device_tokens', 'bank_accounts', 'events', 'event_schedules', 'payments',
            'contributions', 'withdrawals', 'notifications', 'webhook_events',
        ] as $table) {
            $this->assertSame(10, DB::table($table)->count(), "{$table} should have 10 rows");
        }

        // Derived rows: an owner row per event + 10 memberships, and a ledger entry per contribution and withdrawal.
        $this->assertSame(20, DB::table('event_members')->count());
        $this->assertSame(20, DB::table('wallet_transactions')->count());
    }

    public function test_the_demo_user_gets_a_consistent_home_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        Sanctum::actingAs(User::where('phone', UserSeeder::DEMO_PHONE)->firstOrFail(), [TokenAbility::AccessApi->value]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(3, 'events.owned')
            ->assertJsonCount(2, 'events.joined')
            ->assertJsonCount(2, 'invitations')
            // Credits 10,506 + 501 − processed 3,850 − pending 1,500.
            ->assertJsonPath('wallet.balance', 5657)
            // Soonest first: the past Para Picnic, then the Durga Puja.
            ->assertJsonPath('events.owned.0.name', 'Para Picnic 2026')
            ->assertJsonPath('events.owned.1.name', 'Sarbojanin Durga Puja')
            ->assertJsonPath('events.owned.1.total_collected', 10506)
            ->assertJsonPath('events.owned.1.member_count', 4)
            ->assertJsonPath('events.owned.1.my_contribution', 5001);

        $inbox = collect($this->getJson('/api/v1/notifications')->assertOk()->json('data'));
        $this->assertSame(['invitation', 'invitation', 'payment_received'], $inbox->whereNull('read_at')->pluck('type')->sort()->values()->all());
        $this->assertSame(4, $inbox->whereNotNull('read_at')->where('type', 'invitation_accepted')->count());
    }

    public function test_no_wallet_goes_negative(): void
    {
        $this->seed(DatabaseSeeder::class);

        User::each(function (User $user) {
            Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
            $this->assertGreaterThanOrEqual(0, $this->getJson('/api/v1/wallet')->json('balance'), "{$user->name}'s balance");
        });
    }
}
