<?php

namespace App\Core\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

interface AuthInterface
{
    public function findByPhone(string $phone): ?User;

    /**
     * Create a user straight away, without phone verification (used while OTP is disabled).
     *
     * @param  array{name: string, phone: string, password: string}  $data
     */
    public function createUser(array $data): User;

    /**
     * Keep a registration until its phone number is verified (the user is not created yet).
     *
     * @param  array{name: string, phone: string, password: string}  $data
     */
    public function storePendingRegistration(array $data): void;

    public function hasPendingRegistration(string $phone): bool;

    /**
     * Create the user from a verified pending registration, or null if it expired.
     */
    public function completeRegistration(string $phone): ?User;

    /**
     * Issue an access + refresh token pair for a new session.
     *
     * @return array{token: string, token_type: string, expires_at: string, refresh_token: string, refresh_token_expires_at: string}
     */
    public function issueTokens(User $user, ?string $sessionId = null): array;

    /**
     * Revoke the session the refresh token belongs to and issue a new pair for it.
     * Returns null when the refresh token was already used by a concurrent request.
     *
     * @return array{token: string, token_type: string, expires_at: string, refresh_token: string, refresh_token_expires_at: string}|null
     */
    public function refreshTokens(User $user, PersonalAccessToken $refreshToken): ?array;

    /**
     * Revoke both tokens of the session the given token belongs to.
     */
    public function revokeSession(User $user, PersonalAccessToken $token): void;

    /**
     * Sign out every device except the one the given token belongs to (e.g. after a password change).
     */
    public function revokeOtherSessions(User $user, PersonalAccessToken $token): void;
}
