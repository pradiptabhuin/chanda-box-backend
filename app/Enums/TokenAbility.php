<?php

namespace App\Enums;

/**
 * Sanctum abilities that separate access tokens from refresh tokens, so a
 * refresh token cannot call the API and an access token cannot mint new tokens.
 */
enum TokenAbility: string
{
    case AccessApi = 'access-api';
    case IssueAccessToken = 'issue-access-token';
}
