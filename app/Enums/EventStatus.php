<?php

namespace App\Enums;

/**
 * Lifecycle of a chanda event.
 */
enum EventStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
