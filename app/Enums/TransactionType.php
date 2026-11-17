<?php

namespace App\Enums;

/**
 * Direction of a wallet ledger entry.
 */
enum TransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
