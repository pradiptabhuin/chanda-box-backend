<?php

namespace App\Core\Events;

use App\Enums\EventScope;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EventRepository implements EventInterface
{
    private const EVENT_FIELDS = ['name', 'description', 'location', 'proposed_amount', 'target_amount', 'start_date', 'end_date'];

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

    public function findForUser(User $user, int $eventId): Event
    {
        return $this->withMembershipOf($this->withUserTotals(Event::query(), $user), $user)
            ->with('schedules')
            ->findOrFail($eventId);
    }

    public function create(User $owner, array $data): Event
    {
        $event = DB::transaction(function () use ($owner, $data) {
            $event = $owner->ownedEvents()->create(Arr::only($data, self::EVENT_FIELDS));

            $event->members()->create([
                'user_id' => $owner->id,
                'phone' => $owner->phone,
                'role' => MemberRole::Owner,
                'status' => MemberStatus::Joined,
                'added_at' => now(),
            ]);

            $this->replaceCurriculum($event, $data['curriculum'] ?? []);

            return $event;
        });

        return $this->findForUser($owner, $event->id);
    }

    public function update(Event $event, array $data): Event
    {
        DB::transaction(function () use ($event, $data) {
            $event->update(Arr::only($data, self::EVENT_FIELDS));

            if (array_key_exists('curriculum', $data)) {
                $this->replaceCurriculum($event, $data['curriculum']);
            }
        });

        return $this->findForUser($event->owner, $event->id);
    }

    public function members(Event $event): Collection
    {
        $totals = $event->contributions()
            ->toBase()
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(amount) as total')
            ->pluck('total', 'user_id');

        return $event->members()
            ->whereIn('status', [MemberStatus::Joined, MemberStatus::Invited])
            ->with('user:id,first_name,middle_name,last_name')
            ->orderByRaw('case when role = ? then 0 else 1 end', [MemberRole::Owner->value])
            ->orderByRaw('case when status = ? then 0 else 1 end', [MemberStatus::Joined->value])
            ->orderBy('added_at')
            ->orderBy('id')
            ->get()
            ->each(fn (EventMember $member) => $member->setAttribute('total_contributed', $totals[$member->user_id] ?? 0));
    }

    public function contributions(Event $event): Collection
    {
        return $event->contributions()
            ->with('user:id,first_name,middle_name,last_name')
            ->latest('paid_at')
            ->latest('id')
            ->get();
    }

    /**
     * @param  list<array{title: string, date: string, description?: string|null}>  $items
     */
    private function replaceCurriculum(Event $event, array $items): void
    {
        $event->schedules()->delete();

        $event->schedules()->createMany(array_map(fn (array $item, int $index) => [
            'title' => $item['title'],
            'scheduled_at' => $item['date'],
            'description' => $item['description'] ?? null,
            'sort_order' => $index,
        ], $items, array_keys($items)));
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
     * The viewer's own member row as `my_member_id` / `my_status`, so the app can tell a joined
     * member from a pending invitee (and accept or decline right from the event).
     * Must come after withUserTotals(), which selects `events.*`.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function withMembershipOf(Builder $query, User $user): Builder
    {
        $membership = fn (string $column) => EventMember::query()
            ->select($column)
            ->whereColumn('event_id', 'events.id')
            ->where('user_id', $user->id)
            ->limit(1);

        return $query->addSelect([
            'my_member_id' => $membership('id'),
            'my_status' => $membership('status'),
        ]);
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
