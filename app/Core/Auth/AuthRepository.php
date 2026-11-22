<?php

namespace App\Core\Auth;

use App\Core\Invitations\InvitationInterface;
use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Both tokens of a login share the same token name ("session:{uuid}"), which is how
 * a refresh or logout finds and revokes the matching access token.
 */
class AuthRepository implements AuthInterface
{
    public function __construct(private InvitationInterface $invitationInterface) {}

    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $phone)->first();
    }

    public function createUser(array $data): User
    {
        return $this->registered(User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => $data['password'],
        ]));
    }

    public function storePendingRegistration(array $data): void
    {
        Cache::put($this->pendingRegistrationKey($data['phone']), [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
        ], now()->addMinutes(config('services.otp.ttl')));
    }

    public function hasPendingRegistration(string $phone): bool
    {
        return Cache::has($this->pendingRegistrationKey($phone));
    }

    public function completeRegistration(string $phone): ?User
    {
        $pending = Cache::pull($this->pendingRegistrationKey($phone));

        if ($pending === null) {
            return null;
        }

        return $this->registered(User::create([
            ...$pending,
            'phone_verified_at' => now(),
        ]));
    }

    public function issueTokens(User $user, ?string $sessionId = null): array
    {
        $sessionName = 'session:'.($sessionId ?? Str::uuid());
        $accessExpiresAt = now()->addMinutes(config('sanctum.access_token_expiration'));
        $refreshExpiresAt = now()->addMinutes(config('sanctum.refresh_token_expiration'));

        $accessToken = $user->createToken($sessionName, [TokenAbility::AccessApi->value], $accessExpiresAt);
        $refreshToken = $user->createToken($sessionName, [TokenAbility::IssueAccessToken->value], $refreshExpiresAt);

        return [
            'token' => $accessToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $accessExpiresAt->toIso8601ZuluString(),
            'refresh_token' => $refreshToken->plainTextToken,
            'refresh_token_expires_at' => $refreshExpiresAt->toIso8601ZuluString(),
        ];
    }

    public function refreshTokens(User $user, PersonalAccessToken $refreshToken): ?array
    {
        return DB::transaction(function () use ($user, $refreshToken) {
            if (! PersonalAccessToken::whereKey($refreshToken->getKey())->lockForUpdate()->exists()) {
                return null;
            }

            $this->revokeSession($user, $refreshToken);

            return $this->issueTokens($user, Str::after($refreshToken->name, 'session:'));
        });
    }

    public function revokeSession(User $user, PersonalAccessToken $token): void
    {
        $user->tokens()->where('name', $token->name)->delete();
    }

    public function revokeOtherSessions(User $user, PersonalAccessToken $token): void
    {
        $user->tokens()->where('name', '!=', $token->name)->delete();
    }

    /**
     * Invitations sent to this phone before the account existed now belong to it.
     */
    private function registered(User $user): User
    {
        $this->invitationInterface->claimForNewUser($user);

        return $user;
    }

    private function pendingRegistrationKey(string $phone): string
    {
        return "pending_registration:{$phone}";
    }
}
