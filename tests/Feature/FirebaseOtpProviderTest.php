<?php

namespace Tests\Feature;

use App\Core\Otp\FirebaseOtpProvider;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirebaseOtpProviderTest extends TestCase
{
    private const PROJECT_ID = 'chanda-box-test';

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);
        $this->privateKey = $privateKeyPem;
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'securetoken'], $key), null, $key, 1);
        openssl_x509_export($certificate, $certificatePem);

        Http::fake([
            'www.googleapis.com/*' => Http::response(['test-kid' => $certificatePem], 200, ['Cache-Control' => 'public, max-age=3600']),
        ]);
    }

    public function test_accepts_a_valid_token_for_the_phone(): void
    {
        $this->assertTrue($this->provider()->verify('9800000002', $this->idToken()));
    }

    public function test_rejects_a_token_for_another_phone(): void
    {
        $this->assertFalse($this->provider()->verify('9800000003', $this->idToken()));
    }

    public function test_rejects_a_token_for_another_project(): void
    {
        $this->assertFalse($this->provider()->verify('9800000002', $this->idToken(['aud' => 'other-project'])));
    }

    public function test_rejects_a_stale_sign_in(): void
    {
        $this->assertFalse($this->provider()->verify('9800000002', $this->idToken(['auth_time' => time() - 3600])));
    }

    public function test_rejects_an_expired_token(): void
    {
        $this->assertFalse($this->provider()->verify('9800000002', $this->idToken(['exp' => time() - 120])));
    }

    public function test_rejects_a_token_signed_with_another_key(): void
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $otherKey);
        $token = JWT::encode($this->claims(), $otherKey, 'RS256', 'test-kid');

        $this->assertFalse($this->provider()->verify('9800000002', $token));
    }

    public function test_rejects_garbage(): void
    {
        $this->assertFalse($this->provider()->verify('9800000002', '123456'));
    }

    public function test_rejects_everything_without_a_project_id(): void
    {
        $this->assertFalse((new FirebaseOtpProvider(null))->verify('9800000002', $this->idToken()));
    }

    public function test_send_does_not_call_any_api(): void
    {
        $this->provider()->send('9800000002');

        Http::assertNothingSent();
    }

    private function provider(): FirebaseOtpProvider
    {
        return new FirebaseOtpProvider(self::PROJECT_ID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function idToken(array $overrides = []): string
    {
        return JWT::encode($this->claims($overrides), $this->privateKey, 'RS256', 'test-kid');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return [
            'iss' => 'https://securetoken.google.com/'.self::PROJECT_ID,
            'aud' => self::PROJECT_ID,
            'sub' => 'firebase-uid-123',
            'iat' => time(),
            'exp' => time() + 3600,
            'auth_time' => time(),
            'phone_number' => '+919800000002',
            ...$overrides,
        ];
    }
}
