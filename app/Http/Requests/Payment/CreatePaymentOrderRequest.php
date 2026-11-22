<?php

namespace App\Http\Requests\Payment;

/**
 * Body of POST /events/{event}/payments/order: `{ "amount": 1001, "member_id"?: 12 }` in rupees.
 */
class CreatePaymentOrderRequest extends ContributionRequest
{
    /**
     * Upper bound of a single UPI payment.
     */
    public const MAX_AMOUNT = 100_000;

    protected function maxAmount(): int
    {
        return self::MAX_AMOUNT;
    }
}
