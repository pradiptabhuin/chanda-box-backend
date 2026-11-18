<?php

namespace App\Core\Wallet;

use App\Models\User;

interface WalletInterface
{
    /**
     * balance = successful credits − successful debits − pending debits (money on its way out).
     *
     * @return array{balance: float, total_received: float, total_withdrawn: float, pending_withdrawal: float}
     */
    public function summary(User $user): array;
}
