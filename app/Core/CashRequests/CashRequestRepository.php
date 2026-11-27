<?php

namespace App\Core\CashRequests;

use App\Core\Contributions\ContributionRecorder;
use App\Enums\CashRequestStatus;
use App\Enums\PaymentMethod;
use App\Models\CashRequest;
use App\Models\Event;
use App\Models\User;
use App\Notifications\CashRequestReceived;
use App\Notifications\CashRequestReviewed;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRequestRepository implements CashRequestInterface
{
    public function __construct(private ContributionRecorder $recorder) {}

    public function pendingFor(User $viewer, Event $event): Collection
    {
        return $event->cashRequests()
            ->where('status', CashRequestStatus::Pending)
            ->when($event->owner_id !== $viewer->id, fn (Builder $query) => $query->where('user_id', $viewer->id))
            ->with(['user:'.User::SUMMARY_COLUMNS, 'requester:'.User::SUMMARY_COLUMNS])
            ->latest()
            ->latest('id')
            ->get();
    }

    public function create(
        User $requester,
        Event $event,
        float $amount,
        ?User $member = null,
        ?string $note = null,
        PaymentMethod $method = PaymentMethod::Cash,
        ?string $utr = null,
    ): CashRequest {
        $member ??= $requester;
        $details = ['amount' => $amount, 'method' => $method, 'utr' => $utr, 'note' => $note];

        return DB::transaction(function () use ($requester, $event, $member, $details) {
            if ($details['utr'] !== null) {
                $this->ensureUtrIsUnused($event, $details['utr']);
            }

            if ($event->owner_id === $requester->id) {
                $cashRequest = $this->newRequest($requester, $event, $member, $details, CashRequestStatus::Approved);
                $cashRequest->update(['reviewed_by' => $requester->id, 'reviewed_at' => now()]);
                $this->recordContribution($cashRequest, $event, payer: $requester);

                return $cashRequest;
            }

            $alreadyPending = $event->cashRequests()
                ->where('user_id', $member->id)
                ->where('status', CashRequestStatus::Pending)
                ->lockForUpdate()
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'amount' => 'You already have a payment waiting for the organiser to confirm.',
                ]);
            }

            $cashRequest = $this->newRequest($requester, $event, $member, $details, CashRequestStatus::Pending);

            DB::afterCommit(fn () => $event->owner->notify(new CashRequestReceived($cashRequest)));

            return $cashRequest;
        });
    }

    public function approve(User $owner, CashRequest $cashRequest): CashRequest
    {
        return $this->resolve($cashRequest, function (CashRequest $locked) use ($owner) {
            $locked->update([
                'status' => CashRequestStatus::Approved,
                'reviewed_by' => $owner->id,
                'reviewed_at' => now(),
            ]);

            $this->recordContribution($locked, $locked->event()->withTrashed()->firstOrFail(), payer: $locked->requester);
            $this->notifyReviewed($locked);
        });
    }

    public function reject(User $owner, CashRequest $cashRequest, ?string $reason = null): CashRequest
    {
        return $this->resolve($cashRequest, function (CashRequest $locked) use ($owner, $reason) {
            $locked->update([
                'status' => CashRequestStatus::Rejected,
                'reviewed_by' => $owner->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->notifyReviewed($locked);
        });
    }

    public function cancel(CashRequest $cashRequest): CashRequest
    {
        return $this->resolve($cashRequest, fn (CashRequest $locked) => $locked->update([
            'status' => CashRequestStatus::Cancelled,
        ]));
    }

    /**
     * A UTR is one UPI transfer: it can't be claimed twice in the same event (a rejected or
     * cancelled claim frees it again).
     *
     * @throws ValidationException
     */
    private function ensureUtrIsUnused(Event $event, string $utr): void
    {
        $taken = $event->cashRequests()
            ->where('utr', $utr)
            ->whereIn('status', [CashRequestStatus::Pending, CashRequestStatus::Approved])
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['utr' => 'This UPI reference has already been used for this event.']);
        }
    }

    /**
     * @param  array{amount: float, method: PaymentMethod, utr: string|null, note: string|null}  $details
     */
    private function newRequest(
        User $requester,
        Event $event,
        User $member,
        array $details,
        CashRequestStatus $status,
    ): CashRequest {
        /** @var CashRequest $cashRequest */
        $cashRequest = $event->cashRequests()->create([
            ...$details,
            'user_id' => $member->id,
            'requested_by' => $requester->id,
            'status' => $status,
        ]);

        return $cashRequest->setRelation('event', $event)
            ->setRelation('user', $member)
            ->setRelation('requester', $requester);
    }

    /**
     * Locks the row so a double tap (or owner and member at once) can't handle it twice.
     *
     * @param  Closure(CashRequest): mixed  $change
     */
    private function resolve(CashRequest $cashRequest, Closure $change): CashRequest
    {
        return DB::transaction(function () use ($cashRequest, $change) {
            /** @var CashRequest $locked */
            $locked = CashRequest::query()->whereKey($cashRequest->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['status' => 'This payment was already handled.']);
            }

            $change($locked);

            return $locked->load(['user:'.User::SUMMARY_COLUMNS, 'requester:'.User::SUMMARY_COLUMNS]);
        });
    }

    private function recordContribution(CashRequest $cashRequest, Event $event, User $payer): void
    {
        $this->recorder->record(
            event: $event,
            member: $cashRequest->user,
            payer: $payer,
            amount: $cashRequest->amount,
            method: $cashRequest->method,
            reference: $cashRequest->utr ?? strtoupper($cashRequest->method->value)."-{$cashRequest->id}",
            source: $cashRequest,
            note: $cashRequest->note,
        );
    }

    private function notifyReviewed(CashRequest $cashRequest): void
    {
        DB::afterCommit(fn () => $cashRequest->user->notify(new CashRequestReviewed($cashRequest)));
    }
}
