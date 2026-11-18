<?php

namespace App\Core\Events;

use App\Enums\EventScope;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type EventData array{name: string, description?: string|null, location?: string|null, proposed_amount: numeric-string|float|int, target_amount?: numeric-string|float|int|null, start_date: string, end_date?: string|null, curriculum?: list<array{title: string, date: string, description?: string|null}>}
 */
interface EventInterface
{
    /**
     * Events the user organises and/or has joined, soonest first, with the per-user
     * totals the Event resource needs (see EventResource).
     *
     * Pass null to get both scopes in one query; tell them apart with `owner_id`.
     *
     * @return Collection<int, Event>
     */
    public function forUser(User $user, ?EventScope $scope = null): Collection;

    /**
     * One event with its curriculum and the totals as seen by the user. 404 if it doesn't exist.
     */
    public function findForUser(User $user, int $eventId): Event;

    /**
     * Create the event, its owner membership and curriculum in one transaction.
     *
     * @param  EventData  $data
     */
    public function create(User $owner, array $data): Event;

    /**
     * Update the event. A `curriculum` key replaces the whole programme.
     *
     * @param  EventData  $data
     */
    public function update(Event $event, array $data): Event;

    /**
     * Owner first, then joined members and pending invitees (declined/removed are left out),
     * each with `total_contributed` set.
     *
     * @return Collection<int, EventMember>
     */
    public function members(Event $event): Collection;

    /**
     * Newest first, with the payer loaded.
     *
     * @return Collection<int, Contribution>
     */
    public function contributions(Event $event): Collection;
}
