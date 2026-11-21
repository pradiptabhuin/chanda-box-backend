<?php

namespace Tests\Feature;

use App\Core\Push\FcmPushProvider;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class FcmPushProviderTest extends TestCase
{
    private const PROJECT_ID = 'chanda-box-test';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/chanda-box-test/messages:send';

    private string $privateKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);
        $this->privateKey = $privateKeyPem;
        $this->publicKey = openssl_pkey_get_details($key)['key'];
    }

    public function test_sends_one_message_per_token_with_an_oauth_access_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/chanda-box-test/messages/1']),
        ]);

        $invalid = $this->provider()->send(['token-a', 'token-b'], 'New invitation', 'Ananya invited you', ['type' => 'invitation', 'event_id' => 4]);

        $this->assertSame([], $invalid);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            $claims = JWT::decode($request['assertion'], new Key($this->publicKey, 'RS256'));

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $claims->iss === 'push@chanda-box-test.iam.gserviceaccount.com'
                && $claims->scope === 'https://www.googleapis.com/auth/firebase.messaging';
        });

        Http::assertSent(fn (Request $request) => $request->url() === self::SEND_URL
            && $request->hasHeader('Authorization', 'Bearer ya29.test')
            && $request['message']['token'] === 'token-a'
            && $request['message']['notification'] === ['title' => 'New invitation', 'body' => 'Ananya invited you']
            && (array) $request['message']['data'] === ['type' => 'invitation', 'event_id' => '4']
            && $request['message']['android']['notification']['channel_id'] === 'chanda_updates');

        Http::assertSent(fn (Request $request) => $request->url() === self::SEND_URL && $request['message']['token'] === 'token-b');
    }

    public function test_access_token_is_cached_between_sends(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response([]),
        ]);

        $this->provider()->send(['token-a'], 'Hi', 'There');
        $this->provider()->send(['token-a'], 'Hi', 'Again');

        Http::assertSentCount(3);
    }

    public function test_reports_unregistered_and_malformed_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => function (Request $request) {
                return match ($request['message']['token']) {
                    'gone' => Http::response(['error' => [
                        'code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.',
                        'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
                    ]], 404),
                    'garbage' => Http::response(['error' => [
                        'code' => 400, 'status' => 'INVALID_ARGUMENT',
                        'message' => 'The registration token is not a valid FCM registration token',
                    ]], 400),
                    'busy' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'Try later']], 503),
                    default => Http::response([]),
                };
            },
        ]);

        $invalid = $this->provider()->send(['ok', 'gone', 'garbage', 'busy'], 'Hi', 'There');

        $this->assertEqualsCanonicalizing(['gone', 'garbage'], $invalid);
    }

    public function test_rejects_a_missing_credentials_file(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FcmPushProvider::fromCredentialsFile(storage_path('framework/testing/missing.json'));
    }

    private function provider(): FcmPushProvider
    {
        return new FcmPushProvider([
            'project_id' => self::PROJECT_ID,
            'client_email' => 'push@chanda-box-test.iam.gserviceaccount.com',
            'private_key' => $this->privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }
}
