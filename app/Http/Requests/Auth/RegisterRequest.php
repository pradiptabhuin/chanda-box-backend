<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

class RegisterRequest extends PhoneRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => [...$this->phoneRules(), 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'phone.unique' => 'This phone number is already registered.',
        ];
    }
}
