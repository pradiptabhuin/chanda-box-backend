<?php

namespace App\Enums;

/**
 * How a contribution was paid.
 */
enum PaymentMethod: string
{
    case UpiQr = 'upi_qr';
    case Razorpay = 'razorpay';
    case Cash = 'cash';

    /**
     * Online money goes through Razorpay (and Route to the organiser); cash is hand to hand.
     */
    public function isOnline(): bool
    {
        return $this !== self::Cash;
    }
}
