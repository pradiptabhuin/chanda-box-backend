<?php

namespace App\Core\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes messages to the application log instead of sending them. For local development and tests.
 */
class LogSmsProvider implements SmsInterface
{
    public function send(string $phone, string $message): void
    {
        Log::info("SMS to {$phone}: {$message}", ['phone' => $phone, 'message' => $message]);
    }
}
