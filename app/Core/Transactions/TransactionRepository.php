<?php

namespace App\Core\Transactions;

use App\Enums\TransactionFilter;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class TransactionRepository implements TransactionInterface
{
    /**
     * Who handed over the money: the owner when paying on a member's behalf, else the member.
     */
    private const PAYER = 'coalesce(contributions.paid_by, contributions.user_id)';

    public function summary(User $user): array
    {
        $received = 'events.owner_id = ? and '.self::PAYER.' <> ?';
        $sent = self::PAYER.' = ?';

        // One query: conditional sums over every contribution the user is on either side of.
        $totals = $this->involving($user)->toBase()
            ->selectRaw("coalesce(sum(case when {$received} then contributions.amount end), 0) as received", [$user->id, $user->id])
            ->selectRaw("coalesce(sum(case when {$sent} then contributions.amount end), 0) as sent", [$user->id])
            ->selectRaw("count(case when {$received} then 1 end) as received_count", [$user->id, $user->id])
            ->selectRaw("count(case when {$sent} then 1 end) as sent_count", [$user->id])
            ->first();

        return [
            'received' => (float) $totals->received,
            'sent' => (float) $totals->sent,
            'total' => round((float) $totals->received + (float) $totals->sent, 2),
            'received_count' => (int) $totals->received_count,
            'sent_count' => (int) $totals->sent_count,
        ];
    }

    public function history(User $user, TransactionFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $query = match ($filter) {
            TransactionFilter::All => $this->involving($user),
            TransactionFilter::Received => $this->joinEvents()
                ->where('events.owner_id', $user->id)
                ->whereRaw(self::PAYER.' <> ?', [$user->id]),
            TransactionFilter::Sent => $this->joinEvents()->whereRaw(self::PAYER.' = ?', [$user->id]),
        };

        return $query
            ->select('contributions.*')
            ->with([
                'event' => fn ($q) => $q->withTrashed()->select('id', 'name', 'owner_id')->with('owner:'.User::SUMMARY_COLUMNS),
                'user:'.User::SUMMARY_COLUMNS,
                'payer:'.User::SUMMARY_COLUMNS,
                'payment:id,platform_fee,transfer_status',
            ])
            ->orderByDesc('contributions.paid_at')
            ->orderByDesc('contributions.id')
            ->paginate($perPage);
    }

    /**
     * @return Builder<Contribution>
     */
    private function involving(User $user): Builder
    {
        return $this->joinEvents()->where(fn (Builder $q) => $q
            ->where('events.owner_id', $user->id)
            ->orWhereRaw(self::PAYER.' = ?', [$user->id]));
    }

    /**
     * Soft-deleted events keep their history, so the join ignores `deleted_at`.
     *
     * @return Builder<Contribution>
     */
    private function joinEvents(): Builder
    {
        return Contribution::query()->join('events', 'events.id', '=', 'contributions.event_id');
    }
}
