<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /events/{event}/payments/order: `{ "amount": 1001 }` in rupees.
 */
class CreatePaymentOrderRequest extends FormRequest
{
    /**
     * Upper bound of a single UPI payment.
     */
    public const MAX_AMOUNT = 100_000;

    /**
     * Owner or joined member of an active event (checked before validation).
     */
    public function authorize(): bool
    {
        return $this->user()->can('contribute', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:'.self::MAX_AMOUNT, 'decimal:0,2'],
        ];
    }

    public function amount(): float
    {
        return (float) $this->validated('amount');
    }
}
