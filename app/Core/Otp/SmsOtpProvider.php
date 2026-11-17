<?php

namespace App\Core\Otp;

use App\Core\Sms\SmsInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Generates, stores and checks the OTP on the server; only delivery is delegated to the
 * configured SMS gateway. The code is kept hashed, expires after services.otp.ttl minutes
 * and is invalidated after services.otp.max_attempts wrong tries.
 */
class SmsOtpProvider implements OtpInterface
{
    public function __construct(private SmsInterface $sms) {}

    public function driver(): string
    {
        return 'sms';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function send(string $phone): void
    {
        $otp = (string) random_int(100000, 999999);
        $ttl = config('services.otp.ttl');

        Cache::put($this->cacheKey($phone), [
            'hash' => Hash::make($otp),
            'attempts' => 0,
        ], now()->addMinutes($ttl));

        $this->sms->send($phone, "{$otp} is your ".config('app.name')." OTP. It is valid for {$ttl} minutes. Do not share it with anyone.");
    }

    public function verify(string $phone, string $otp): bool
    {
        $key = $this->cacheKey($phone);
        $pending = Cache::get($key);

        if ($pending === null || $pending['attempts'] >= config('services.otp.max_attempts')) {
            return false;
        }

        if (! Hash::check($otp, $pending['hash'])) {
            $pending['attempts']++;
            Cache::put($key, $pending, now()->addMinutes(config('services.otp.ttl')));

            return false;
        }

        Cache::forget($key);

        return true;
    }

    private function cacheKey(string $phone): string
    {
        return "otp:{$phone}";
    }
}
