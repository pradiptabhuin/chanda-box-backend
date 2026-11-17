<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Auth flows with OTP verification on (OTP_DRIVER=sms, SMS_DRIVER=log).
 */
class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Latest OTP sent to each phone, read from the logged SMS.
     *
     * @var array<string, string>
     */
    private array $sentOtps = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'services.otp.driver' => 'sms',
            'services.sms.driver' => 'log',
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if (isset($event->context['message']) && preg_match('/^(\d{6}) is your/', $event->context['message'], $matches)) {
                $this->sentOtps[$event->context['phone']] = $matches[1];
            }
        });
    }

    public function test_options_report_otp_enabled(): void
    {
        $this->getJson('/api/v1/auth/options')
            ->assertOk()
            ->assertExactJson(['otp_enabled' => true, 'otp_driver' => 'sms']);
    }

    public function test_register_sends_otp_without_creating_the_user(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertOk()
            ->assertJson(['otp_driver' => 'sms']);

        $this->assertArrayHasKey('9800000002', $this->sentOtps);
        $this->assertDatabaseMissing('users', ['phone' => '9800000002']);
    }

    public function test_verify_otp_creates_the_user_and_returns_a_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '9800000002',
            'otp' => $this->sentOtps['9800000002'],
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'refresh_token', 'refresh_token_expires_at', 'user' => ['id', 'name', 'phone', 'email', 'created_at']])
            ->assertJsonPath('user.phone', '9800000002')
            ->assertJsonPath('user.name', 'Arijit Sen')
            ->assertJsonPath('user.phone_verified', true);

        $user = User::where('phone', '9800000002')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);

        $this->postJson('/api/v1/auth/login', ['phone' => '9800000002', 'password' => 'secret1'])->assertOk();
    }

    public function test_verify_otp_rejects_a_wrong_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9800000002', 'otp' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');

        $this->assertDatabaseMissing('users', ['phone' => '9800000002']);
    }

    public function test_otp_is_locked_after_too_many_wrong_attempts(): void
    {
        config(['services.otp.max_attempts' => 2]);
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9800000002', 'otp' => '000000']);
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9800000002', 'otp' => '000001']);

        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9800000002', 'otp' => $this->sentOtps['9800000002']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');
    }

    public function test_verify_otp_without_pending_registration_fails(): void
    {
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9800000002', 'otp' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_resend_otp_sends_a_new_code(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());
        unset($this->sentOtps['9800000002']);

        $this->postJson('/api/v1/auth/resend-otp', ['phone' => '9800000002'])->assertOk();

        $this->assertArrayHasKey('9800000002', $this->sentOtps);
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '9800000002', 'otp' => $this->sentOtps['9800000002']])
            ->assertCreated();
    }

    public function test_resend_otp_for_unknown_phone_fails(): void
    {
        $this->postJson('/api/v1/auth/resend-otp', ['phone' => '9800000002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_otp_sending_is_rate_limited_per_phone(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertOk();
        }

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertTooManyRequests();
    }

    public function test_login_with_otp(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->postJson('/api/v1/auth/login/otp', ['phone' => '9876543210'])->assertOk();

        $this->postJson('/api/v1/auth/login/otp/verify', ['phone' => '9876543210', 'otp' => $this->sentOtps['9876543210']])
            ->assertOk()
            ->assertJsonPath('user.phone', '9876543210');
    }

    public function test_login_otp_for_unknown_phone_fails(): void
    {
        $this->postJson('/api/v1/auth/login/otp', ['phone' => '9876543210'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertArrayNotHasKey('9876543210', $this->sentOtps);
    }

    public function test_login_otp_cannot_be_reused(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $this->postJson('/api/v1/auth/login/otp', ['phone' => '9876543210']);
        $otp = $this->sentOtps['9876543210'];

        $this->postJson('/api/v1/auth/login/otp/verify', ['phone' => '9876543210', 'otp' => $otp])->assertOk();
        $this->postJson('/api/v1/auth/login/otp/verify', ['phone' => '9876543210', 'otp' => $otp])->assertUnprocessable();
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
}
