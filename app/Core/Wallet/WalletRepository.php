<?php

namespace App\Core\Wallet;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\User;

class WalletRepository implements WalletInterface
{
    public function summary(User $user): array
    {
        $credit = TransactionType::Credit->value;
        $debit = TransactionType::Debit->value;
        $success = TransactionStatus::Success->value;
        $pending = TransactionStatus::Pending->value;

        // One query: each total is a conditional sum over the user's ledger.
        $totals = $user->walletTransactions()->toBase()
            ->selectRaw('coalesce(sum(case when type = ? and status = ? then amount end), 0) as total_received', [$credit, $success])
            ->selectRaw('coalesce(sum(case when type = ? and status = ? then amount end), 0) as total_withdrawn', [$debit, $success])
            ->selectRaw('coalesce(sum(case when type = ? and status = ? then amount end), 0) as pending_withdrawal', [$debit, $pending])
            ->first();

        $received = (float) $totals->total_received;
        $withdrawn = (float) $totals->total_withdrawn;
        $pendingWithdrawal = (float) $totals->pending_withdrawal;

        return [
            'balance' => round($received - $withdrawn - $pendingWithdrawal, 2),
            'total_received' => $received,
            'total_withdrawn' => $withdrawn,
            'pending_withdrawal' => $pendingWithdrawal,
        ];
    }
}
