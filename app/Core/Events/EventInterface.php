<?php

namespace App\Core\Events;

use App\Enums\EventScope;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

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
}
