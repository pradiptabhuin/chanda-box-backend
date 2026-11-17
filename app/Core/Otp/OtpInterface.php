<?php

namespace App\Core\Otp;

interface OtpInterface
{
    /**
     * Driver name returned to the app: "none" (OTP disabled), "sms" (the API sent an SMS
     * code) or "firebase" (verify on device with the Firebase SDK).
     */
    public function driver(): string;

    /**
     * Whether phone numbers are verified with an OTP. When false, registration
     * creates the account straight away and OTP login is unavailable.
     */
    public function isEnabled(): bool;

    /**
     * Send an OTP to a 10-digit Indian mobile number.
     */
    public function send(string $phone): void;

    /**
     * Check the credential the user submitted for the phone number. For the sms driver
     * this is the 6-digit code; for Firebase it is the Firebase ID token.
     */
    public function verify(string $phone, string $otp): bool;
}
