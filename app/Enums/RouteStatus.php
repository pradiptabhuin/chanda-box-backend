<?php

namespace App\Enums;

/**
 * State of a payout account's Razorpay Route linked account.
 */
enum RouteStatus: string
{
    /** Saved locally; Route is switched off so nothing was sent to Razorpay yet. */
    case NotLinked = 'not_linked';
    /** Razorpay is reviewing the account. */
    case Pending = 'pending';
    /** Receives online contributions. */
    case Active = 'active';
    /** Razorpay needs more details (see the reason). */
    case NeedsClarification = 'needs_clarification';
    /** Linking failed (see the reason); can be retried. */
    case Failed = 'failed';
}
