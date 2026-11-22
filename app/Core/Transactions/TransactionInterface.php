<?php

namespace App\Core\Transactions;

use App\Enums\TransactionFilter;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A user's money history, built from contributions (online and cash):
 * - received: contributions to events they organise, paid by someone else;
 * - sent: contributions they paid (for themselves or on a member's behalf).
 */
interface TransactionInterface
{
    /**
     * @return array{received: float, sent: float, total: float, received_count: int, sent_count: int}
     */
    public function summary(User $user): array;

    /**
     * Newest first, with event, member, payer and payment loaded.
     *
     * @return LengthAwarePaginator<int, Contribution>
     */
    public function history(User $user, TransactionFilter $filter, int $perPage = 20): LengthAwarePaginator;
}
