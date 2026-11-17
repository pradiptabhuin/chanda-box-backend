<?php

namespace App\Enums;

/**
 * Settlement state of a wallet ledger entry.
 */
enum TransactionStatus: string
{
    case Success = 'success';
    case Pending = 'pending';
    case Failed = 'failed';
}
