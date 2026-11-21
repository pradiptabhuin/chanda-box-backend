<?php

namespace App\Core\Push;

use Illuminate\Support\Facades\Log;

/**
 * Writes push notifications to the application log instead of sending them. For local development and tests.
 */
class LogPushProvider implements PushInterface
{
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        Log::info('Push to '.count($tokens)." device(s): {$title} — {$body}", ['data' => $data]);

        return [];
    }
}
