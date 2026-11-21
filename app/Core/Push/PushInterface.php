<?php

namespace App\Core\Push;

/**
 * Delivers a push notification to devices. Select the implementation with PUSH_DRIVER
 * (`fcm` for Firebase Cloud Messaging, `log` for local development and tests).
 */
interface PushInterface
{
    /**
     * Send one message to every token.
     *
     * @param  list<string>  $tokens
     * @param  array<string, scalar|null>  $data  Extra key/values the app reads when the notification is tapped.
     * @return list<string> Tokens the provider reported as no longer valid (app uninstalled, token rotated).
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
