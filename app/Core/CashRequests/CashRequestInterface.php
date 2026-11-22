<?php

namespace App\Core\CashRequests;

use App\Models\CashRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

interface CashRequestInterface
{
    /**
     * Requests awaiting the owner: all of them for the owner, only their own for a member.
     *
     * @return Collection<int, CashRequest>
     */
    public function pendingFor(User $viewer, Event $event): Collection;

    /**
     * A member's cash payment waits for the owner's approval (and notifies them). Cash the owner
     * records, for themselves or on `$member`'s behalf, is approved and counted straight away.
     *
     * @throws ValidationException when the member already has a request pending
     */
    public function create(User $requester, Event $event, float $amount, ?User $member = null, ?string $note = null): CashRequest;

    /**
     * @throws ValidationException when the request was already handled
     */
    public function approve(User $owner, CashRequest $cashRequest): CashRequest;

    /**
     * @throws ValidationException when the request was already handled
     */
    public function reject(User $owner, CashRequest $cashRequest, ?string $reason = null): CashRequest;

    /**
     * The requester withdraws a request that hasn't been handled yet.
     *
     * @throws ValidationException when the request was already handled
     */
    public function cancel(CashRequest $cashRequest): CashRequest;
}
