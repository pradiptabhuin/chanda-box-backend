<?php

namespace App\Core\Otp;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Phone Auth: the app sends and checks the SMS code with the Firebase SDK and
 * then posts the resulting Firebase ID token as the "otp". The API only verifies that
 * token (signature, project, freshness) and that it belongs to the expected phone.
 *
 * @see https://firebase.google.com/docs/auth/admin/verify-id-tokens#verify_id_tokens_using_a_third-party_jwt_library
 */
class FirebaseOtpProvider implements OtpInterface
{
    private const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const CERTS_CACHE_KEY = 'firebase:securetoken-certs';

    /**
     * Seconds after the SMS sign-in on the device within which the ID token is accepted.
     */
    private const MAX_AUTH_AGE = 600;

    public function __construct(private ?string $projectId) {}

    public function driver(): string
    {
        return 'firebase';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Nothing to do server side: the app triggers the SMS via FirebaseAuth.verifyPhoneNumber().
     */
    public function send(string $phone): void {}

    public function verify(string $phone, string $otp): bool
    {
        if (blank($this->projectId)) {
            Log::error('FIREBASE_PROJECT_ID is not configured; cannot verify Firebase ID tokens.');

            return false;
        }

        try {
            JWT::$leeway = 60;
            $claims = JWT::decode($otp, $this->publicKeys());
        } catch (Throwable $e) {
            Log::notice('Firebase ID token rejected', ['phone' => $phone, 'reason' => $e->getMessage()]);

            return false;
        }

        return ($claims->aud ?? null) === $this->projectId
            && ($claims->iss ?? null) === "https://securetoken.google.com/{$this->projectId}"
            && filled($claims->sub ?? null)
            && ($claims->auth_time ?? 0) >= now()->timestamp - self::MAX_AUTH_AGE
            && ($claims->phone_number ?? null) === "+91{$phone}";
    }

    /**
     * Google's current signing certificates keyed by "kid", cached for as long as Google allows.
     *
     * @return array<string, Key>
     */
    private function publicKeys(): array
    {
        $certs = Cache::get(self::CERTS_CACHE_KEY);

        if ($certs === null) {
            $response = Http::timeout(10)->get(self::CERTS_URL)->throw();
            $certs = $response->json();

            preg_match('/max-age=(\d+)/', $response->header('Cache-Control'), $matches);
            Cache::put(self::CERTS_CACHE_KEY, $certs, (int) ($matches[1] ?? 3600));
        }

        return array_map(fn (string $cert) => new Key($cert, 'RS256'), $certs);
    }
}
