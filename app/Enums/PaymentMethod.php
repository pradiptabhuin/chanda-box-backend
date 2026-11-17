<?php

namespace App\Enums;

/**
 * How a contribution was paid.
 */
enum PaymentMethod: string
{
    case UpiQr = 'upi_qr';
    case Razorpay = 'razorpay';
}
