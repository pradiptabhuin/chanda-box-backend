<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Contribution;
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
            'contributions', 'notifications', 'webhook_events',
        ] as $table) {
            $this->assertSame(10, DB::table($table)->count(), "{$table} should have 10 rows");
        }

        // Derived rows: an owner row per event + 10 memberships.
        $this->assertSame(20, DB::table('event_members')->count());
        $this->assertSame(1, DB::table('bank_accounts')->where('route_status', 'active')->count());
    }

    public function test_the_demo_user_gets_a_consistent_home_screen(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = User::where('phone', UserSeeder::DEMO_PHONE)->firstOrFail();
        Sanctum::actingAs($demo, [TokenAbility::AccessApi->value]);
        $received = (float) Contribution::whereIn('event_id', $demo->ownedEvents()->select('id'))->where('user_id', '!=', $demo->id)->sum('amount');
        $sent = (float) $demo->contributions()->sum('amount');

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(3, 'events.owned')
            ->assertJsonCount(2, 'events.joined')
            ->assertJsonCount(2, 'invitations')
            ->assertJsonPath('transactions.received', fn ($value) => (float) $value === $received)
            ->assertJsonPath('transactions.sent', fn ($value) => (float) $value === $sent)
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
}
