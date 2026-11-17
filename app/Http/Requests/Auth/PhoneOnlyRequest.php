<?php

namespace App\Http\Requests\Auth;

class PhoneOnlyRequest extends PhoneRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => $this->phoneRules(),
        ];
    }
}
