<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Auth flows with OTP verification off (OTP_DRIVER=none): phone + password only.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_report_otp_disabled(): void
    {
        $this->getJson('/api/v1/auth/options')
            ->assertOk()
            ->assertExactJson(['otp_enabled' => false, 'otp_driver' => 'none']);
    }

    public function test_register_creates_the_user_and_returns_a_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'refresh_token', 'refresh_token_expires_at', 'user' => ['id', 'name', 'phone', 'email', 'phone_verified', 'created_at']])
            ->assertJsonPath('user.phone', '9800000002')
            ->assertJsonPath('user.name', 'Arijit Sen')
            ->assertJsonPath('user.phone_verified', false);

        $this->postJson('/api/v1/auth/login', ['phone' => '9800000002', 'password' => 'secret1'])->assertOk();
    }

    public function test_register_normalises_the_phone_number(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['phone' => '+91 98000-00002']))
            ->assertCreated()
            ->assertJsonPath('user.phone', '9800000002');
    }

    public function test_register_requires_matching_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['password_confirmation' => 'other']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_otp_endpoints_are_unavailable(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/v1/auth/login/otp', ['phone' => '9876543210'])->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson('/api/v1/auth/login/otp/verify', ['phone' => '9876543210', 'otp' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9876543210', 'otp' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('otp');
        $this->postJson('/api/v1/auth/resend-otp', ['phone' => '9876543210'])->assertUnprocessable()->assertJsonValidationErrors('otp');
    }

    public function test_register_rejects_an_already_registered_phone(): void
    {
        User::factory()->create(['phone' => '9800000002']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_register_rejects_invalid_phone_numbers(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['phone' => '12345']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_login_with_password(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/v1/auth/login', ['phone' => '9876543210', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.phone', '9876543210')
            ->assertJsonStructure(['token', 'refresh_token']);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/v1/auth/login', ['phone' => '9876543210', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_tokens_have_one_day_and_sixty_day_lifetimes(): void
    {
        $this->freezeSecond();
        User::factory()->create(['phone' => '9876543210']);

        $session = $this->login();

        $this->assertSame(now()->addDay()->toIso8601ZuluString(), $session['expires_at']);
        $this->assertSame(now()->addDays(60)->toIso8601ZuluString(), $session['refresh_token_expires_at']);
    }

    public function test_access_token_can_call_the_api(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->withToken($session['token'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '9876543210');
    }

    public function test_refresh_token_cannot_call_the_api(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->withToken($session['refresh_token'])->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_access_token_cannot_refresh(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->withToken($session['token'])->postJson('/api/v1/auth/refresh')->assertForbidden();
    }

    public function test_expired_access_token_is_rejected(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->travel(25)->hours();

        $this->withToken($session['token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_refresh_rotates_both_tokens(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->travel(25)->hours();

        $refreshed = $this->withToken($session['refresh_token'])->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['token', 'refresh_token', 'user'])
            ->json();

        $this->assertSame(2, PersonalAccessToken::count());
        $this->assertNull(PersonalAccessToken::findToken($session['refresh_token']));
        $this->assertNull(PersonalAccessToken::findToken($session['token']));
        $this->assertTrue(PersonalAccessToken::findToken($refreshed['token'])->can(TokenAbility::AccessApi->value));

        $this->app['auth']->forgetGuards();
        $this->withToken($refreshed['token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_refresh_token_cannot_be_used_twice(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->withToken($session['refresh_token'])->postJson('/api/v1/auth/refresh')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($session['refresh_token'])->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_expired_refresh_token_is_rejected(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();

        $this->travel(61)->days();

        $this->withToken($session['refresh_token'])->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_session(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $session = $this->login();
        $otherDevice = $this->login();

        $this->withToken($session['token'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($session['token']));
        $this->assertNull(PersonalAccessToken::findToken($session['refresh_token']));
        $this->assertNotNull(PersonalAccessToken::findToken($otherDevice['token']));
        $this->assertNotNull(PersonalAccessToken::findToken($otherDevice['refresh_token']));
    }

    public function test_guest_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return [
            'name' => 'Arijit Sen',
            'phone' => '9800000002',
            'password' => 'secret1',
            'password_confirmation' => 'secret1',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function login(): array
    {
        return $this->postJson('/api/v1/auth/login', ['phone' => '9876543210', 'password' => 'password'])
            ->assertOk()
            ->json();
    }
}
