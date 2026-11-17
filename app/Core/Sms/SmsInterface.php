<?php

namespace App\Core\Sms;

/**
 * Delivers a text message to a phone. Add one implementation per SMS gateway
 * (MSG91, Fast2SMS, Twilio, ...) and select it with SMS_DRIVER.
 */
interface SmsInterface
{
    /**
     * Send a message to a 10-digit Indian mobile number.
     */
    public function send(string $phone, string $message): void;
}
