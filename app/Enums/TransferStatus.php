<?php

namespace App\Enums;

/**
 * Route transfer of a captured contribution to the organiser's linked account.
 */
enum TransferStatus: string
{
    case Pending = 'pending';
    case Transferred = 'transferred';
    case Failed = 'failed';
}
