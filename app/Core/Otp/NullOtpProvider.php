<?php

namespace App\Core\Otp;

/**
 * OTP verification switched off (OTP_DRIVER=none): users register and log in with phone + password only.
 */
class NullOtpProvider implements OtpInterface
{
    public function driver(): string
    {
        return 'none';
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function send(string $phone): void {}

    public function verify(string $phone, string $otp): bool
    {
        return false;
    }
}
