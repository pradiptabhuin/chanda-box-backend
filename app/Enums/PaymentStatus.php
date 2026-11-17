<?php

namespace App\Enums;

/**
 * State of a Razorpay payment attempt.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
}
