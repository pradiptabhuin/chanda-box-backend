<?php

namespace App\Http\Requests\Payout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of PUT /payout-account: the bank plus the KYC details Razorpay needs for a Route linked account.
 */
class SavePayoutAccountRequest extends FormRequest
{
    /**
     * Normalise before validating: upper-case codes, digits-only numbers.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'ifsc' => $this->filled('ifsc') ? strtoupper(trim((string) $this->input('ifsc'))) : null,
            'pan' => $this->filled('pan') ? strtoupper(trim((string) $this->input('pan'))) : null,
            'account_number' => $this->filled('account_number') ? preg_replace('/\D/', '', (string) $this->input('account_number')) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'holder_name' => ['required', 'string', 'max:250'],
            'account_number' => ['required', 'digits_between:9,18'],
            'ifsc' => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank_name' => ['required', 'string', 'max:250'],
            // Razorpay needs an email; the profile's is used when the user has one.
            'email' => [$this->user()->email ? 'nullable' : 'required', 'email', 'max:250'],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'street' => ['required', 'string', 'max:250'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ifsc.regex' => 'Enter a valid IFSC code, e.g. SBIN0001234.',
            'pan.regex' => 'Enter a valid PAN, e.g. ABCDE1234F.',
        ];
    }
}
