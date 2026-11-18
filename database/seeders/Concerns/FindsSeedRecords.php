<?php

namespace Database\Seeders\Concerns;

use App\Models\Event;
use App\Models\User;

/**
 * Seeders refer to records by their natural keys (phone, event name) so each one
 * stays readable and doesn't depend on auto-increment ids.
 */
trait FindsSeedRecords
{
    /** @var array<string, User> */
    private array $usersByPhone = [];

    /** @var array<string, Event> */
    private array $eventsByName = [];

    protected function user(string $phone): User
    {
        return $this->usersByPhone[$phone] ??= User::where('phone', $phone)->firstOrFail();
    }

    protected function event(string $name): Event
    {
        return $this->eventsByName[$name] ??= Event::where('name', $name)->firstOrFail();
    }
}
