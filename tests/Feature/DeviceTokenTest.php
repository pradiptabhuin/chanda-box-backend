<?php

namespace Tests\Feature;

use App\Enums\DevicePlatform;
use App\Enums\TokenAbility;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_token_stores_it_for_the_user(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);

        $this->postJson('/api/v1/me/device-token', ['token' => 'fcm-token-1', 'platform' => 'android'])->assertOk();
        // Registering again (app restart) doesn't duplicate it.
        $this->postJson('/api/v1/me/device-token', ['token' => 'fcm-token-1', 'platform' => 'android'])->assertOk();

        $token = DeviceToken::sole();
        $this->assertSame($user->id, $token->user_id);
        $this->assertSame(DevicePlatform::Android, $token->platform);
        $this->assertNotNull($token->last_seen_at);
    }

    public function test_a_token_moves_to_the_user_who_signed_in_last_on_the_device(): void
    {
        $previous = User::factory()->create();
        DeviceToken::factory()->for($previous)->create(['token' => 'shared-device']);
        $user = User::factory()->create();
        $this->signIn($user);

        $this->postJson('/api/v1/me/device-token', ['token' => 'shared-device', 'platform' => 'ios'])->assertOk();

        $this->assertSame($user->id, DeviceToken::sole()->user_id);
    }

    public function test_validation(): void
    {
        $this->signIn(User::factory()->create());

        $this->postJson('/api/v1/me/device-token', ['token' => '', 'platform' => 'windows'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'platform']);
    }

    public function test_removing_a_token_only_touches_the_users_own(): void
    {
        $user = User::factory()->create();
        DeviceToken::factory()->for($user)->create(['token' => 'mine']);
        DeviceToken::factory()->create(['token' => 'theirs']);
        $this->signIn($user);

        $this->deleteJson('/api/v1/me/device-token', ['token' => 'mine'])->assertOk();
        $this->deleteJson('/api/v1/me/device-token', ['token' => 'theirs'])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'mine']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'theirs']);
    }

    public function test_guests_cannot_register_tokens(): void
    {
        $this->postJson('/api/v1/me/device-token', ['token' => 'x', 'platform' => 'android'])->assertUnauthorized();
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }
}
