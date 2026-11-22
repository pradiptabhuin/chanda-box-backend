<?php

namespace App\Enums;

/**
 * Lifecycle of a cash contribution: a member asks, the event owner confirms they got the cash.
 */
enum CashRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
