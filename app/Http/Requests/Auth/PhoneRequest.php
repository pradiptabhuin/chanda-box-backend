<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for endpoints keyed by an Indian mobile number. The number is
 * normalised to 10 digits (no +91 / 0 prefix, spaces or dashes) before validation.
 */
abstract class PhoneRequest extends FormRequest
{
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => static::normalizePhone($this->input('phone'))]);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function phoneRules(): array
    {
        return ['required', 'string', 'regex:/^[6-9]\d{9}$/'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
        ];
    }
}
