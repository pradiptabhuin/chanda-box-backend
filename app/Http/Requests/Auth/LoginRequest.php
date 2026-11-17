<?php

namespace App\Http\Requests\Auth;

class LoginRequest extends PhoneRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
            'password' => ['required', 'string'],
        ];
    }
}
