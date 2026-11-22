<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /payments/verify: what Razorpay Checkout hands the app on success.
 */
class VerifyPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'razorpay_order_id' => ['required', 'string', 'max:50'],
            'razorpay_payment_id' => ['required', 'string', 'max:50'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ];
    }
}
