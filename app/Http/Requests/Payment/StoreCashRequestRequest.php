<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of POST /events/{event}/cash-requests:
 * `{ "amount": 1001, "method"?: "cash"|"upi", "utr"?: "412345678901", "member_id"?: 12, "note"?: "..." }`.
 * A member reporting a UPI payment must send the 12-digit UTR from their UPI app; the owner may skip it.
 */
class StoreCashRequestRequest extends ContributionRequest
{
    public const MAX_AMOUNT = 10_000_000;

    protected function maxAmount(): int
    {
        return self::MAX_AMOUNT;
    }

    /**
     * UPI apps often show the UTR with spaces: keep the digits only.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('utr')) {
            $this->merge(['utr' => preg_replace('/\s+/', '', (string) $this->input('utr'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpi = $this->input('method') === PaymentMethod::Upi->value;
        $isOwner = $this->event()->owner_id === $this->user()->id;

        return [
            ...parent::rules(),
            'method' => ['sometimes', Rule::enum(PaymentMethod::class)->only(PaymentMethod::organiserConfirmed())],
            'utr' => [
                $isUpi && ! $isOwner ? 'required' : 'nullable',
                Rule::prohibitedIf(! $isUpi),
                'regex:/^[0-9]{12}$/',
            ],
            'note' => ['nullable', 'string', 'max:250'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('method') === PaymentMethod::Upi->value && ! $this->event()->acceptsUpi()) {
                    $validator->errors()->add('method', "The organiser hasn't added a UPI ID for this event.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'utr.required' => 'Enter the 12-digit UPI reference (UTR) from your UPI app.',
            'utr.regex' => 'The UPI reference (UTR) is the 12-digit number in your UPI app.',
            'utr.prohibited' => 'A UPI reference is only needed for UPI payments.',
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('method', PaymentMethod::Cash->value));
    }

    public function utr(): ?string
    {
        return $this->validated('utr');
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }
}
