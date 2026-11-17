<?php

namespace App\Enums;

/**
 * State of a RazorpayX payout.
 */
enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Reversed = 'reversed';
    case Failed = 'failed';
}
