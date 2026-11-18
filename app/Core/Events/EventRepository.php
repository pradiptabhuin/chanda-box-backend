<?php

namespace App\Core\Events;

use App\Enums\EventScope;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EventRepository implements EventInterface
{
    public function forUser(User $user, ?EventScope $scope = null): Collection
    {
        return $this->withUserTotals(Event::query(), $user)
            ->where(fn (Builder $query) => match ($scope) {
                EventScope::Owned => $this->whereOwnedBy($query, $user),
                EventScope::Joined => $this->whereJoinedBy($query, $user),
                null => $this->whereOwnedBy($query, $user)->orWhere(fn (Builder $q) => $this->whereJoinedBy($q, $user)),
            })
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Adds the owner and the aggregates as subqueries, so a list costs two queries in total.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function withUserTotals(Builder $query, User $user): Builder
    {
        return $query
            ->with('owner:id,first_name,middle_name,last_name')
            ->withSum('contributions as total_collected', 'amount')
            ->withCount('joinedMembers as member_count')
            ->withCount(['contributions as contributor_count' => fn (Builder $q) => $q->select(DB::raw('count(distinct user_id)'))])
            ->withSum(['contributions as my_contribution' => fn (Builder $q) => $q->where('user_id', $user->id)], 'amount');
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function whereOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('owner_id', $user->id);
    }

    /**
     * Joined as a member (not as the owner).
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function whereJoinedBy(Builder $query, User $user): Builder
    {
        return $query
            ->where('owner_id', '!=', $user->id)
            ->whereHas('members', fn (Builder $q) => $q
                ->where('user_id', $user->id)
                ->where('role', MemberRole::Member)
                ->where('status', MemberStatus::Joined));
    }
}
