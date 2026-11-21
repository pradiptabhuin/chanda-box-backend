<?php

namespace App\Core\Push;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Firebase Cloud Messaging (HTTP v1 API), authenticated with a service-account key.
 *
 * The service account signs a short JWT that is exchanged for an OAuth access token
 * (cached for about an hour), then one request is sent per device token.
 *
 * @see https://firebase.google.com/docs/cloud-messaging/send-message
 * @see https://developers.google.com/identity/protocols/oauth2/service-account#httprest
 */
class FcmPushProvider implements PushInterface
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /**
     * Android notification channel the app creates (see LocalNotificationService in the app).
     */
    private const ANDROID_CHANNEL_ID = 'chanda_updates';

    /**
     * @param  array{project_id: string, client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    public function __construct(private array $credentials) {}

    /**
     * Build the provider from the service-account JSON downloaded from the Firebase console.
     */
    public static function fromCredentialsFile(?string $path): self
    {
        if (blank($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("FIREBASE_CREDENTIALS file [{$path}] is missing or unreadable.");
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (! is_array($credentials) || blank($credentials['project_id'] ?? null)
            || blank($credentials['client_email'] ?? null) || blank($credentials['private_key'] ?? null)) {
            throw new InvalidArgumentException("FIREBASE_CREDENTIALS file [{$path}] is not a service-account key.");
        }

        return new self($credentials);
    }

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if ($tokens === []) {
            return [];
        }

        $accessToken = $this->accessToken();
        $url = "https://fcm.googleapis.com/v1/projects/{$this->credentials['project_id']}/messages:send";

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $token) => $pool->as($token)
                ->withToken($accessToken)
                ->timeout(10)
                ->post($url, ['message' => $this->message($token, $title, $body, $data)]),
            $tokens,
        ));

        $invalid = [];

        foreach ($responses as $token => $response) {
            if (! $response instanceof Response) {
                Log::warning('FCM request failed', ['error' => $response instanceof \Throwable ? $response->getMessage() : null]);

                continue;
            }

            if ($response->successful()) {
                continue;
            }

            if ($this->isInvalidToken($response)) {
                $invalid[] = (string) $token;

                continue;
            }

            Log::warning('FCM rejected a message', ['status' => $response->status(), 'error' => $response->json('error.message')]);
        }

        return $invalid;
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array<string, mixed>
     */
    private function message(string $token, string $title, string $body, array $data): array
    {
        return [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            // FCM only accepts string values; an empty array must still encode as a JSON object.
            'data' => (object) array_map(fn (mixed $value) => (string) $value, $data),
            'android' => [
                'priority' => 'high',
                'notification' => ['channel_id' => self::ANDROID_CHANNEL_ID, 'sound' => 'default'],
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ];
    }

    /**
     * The device unregistered (app uninstalled / token rotated) or the token is malformed.
     */
    private function isInvalidToken(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        $isUnregistered = collect($response->json('error.details', []))
            ->contains(fn (mixed $detail) => is_array($detail) && ($detail['errorCode'] ?? null) === 'UNREGISTERED');

        return $isUnregistered
            || ($response->status() === 400 && str_contains((string) $response->json('error.message'), 'registration token'));
    }

    /**
     * OAuth access token for the service account, cached until shortly before it expires.
     */
    private function accessToken(): string
    {
        $cacheKey = 'firebase:fcm-access-token:'.$this->credentials['client_email'];
        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        $tokenUri = $this->credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;
        $now = time();

        $assertion = JWT::encode([
            'iss' => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $this->credentials['private_key'], 'RS256');

        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ])->throw();

        $accessToken = (string) $response->json('access_token');
        Cache::put($cacheKey, $accessToken, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $accessToken;
    }
}
