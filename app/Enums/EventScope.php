<?php

namespace App\Enums;

/**
 * Which of the user's events to list: the ones they organise, or the ones they joined as a member.
 */
enum EventScope: string
{
    case Owned = 'owned';
    case Joined = 'joined';
}
