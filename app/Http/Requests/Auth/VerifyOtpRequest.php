<?php

namespace App\Http\Requests\Auth;

class VerifyOtpRequest extends PhoneRequest
{
    /**
     * The "otp" is the 6-digit code, or the Firebase ID token when the OTP driver is firebase.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'otp' => ['required', 'string', 'max:4096'],
        ];
    }
}
