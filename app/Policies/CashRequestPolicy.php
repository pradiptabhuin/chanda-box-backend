<?php

namespace App\Policies;

use App\Models\CashRequest;
use App\Models\User;

/**
 * The event owner confirms or rejects cash; the person who asked can withdraw it.
 */
class CashRequestPolicy
{
    public function review(User $user, CashRequest $cashRequest): bool
    {
        return $cashRequest->event()->withTrashed()->value('owner_id') === $user->id;
    }

    public function cancel(User $user, CashRequest $cashRequest): bool
    {
        return $cashRequest->requested_by === $user->id;
    }
}
