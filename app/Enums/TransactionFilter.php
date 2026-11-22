<?php

namespace App\Enums;

/**
 * Which side of the user's money the transaction history shows.
 */
enum TransactionFilter: string
{
    case All = 'all';
    case Received = 'received';
    case Sent = 'sent';
}
