<?php

namespace App\Enums;

/**
 * Invitation / membership state of an event member row.
 */
enum MemberStatus: string
{
    case Invited = 'invited';
    case Joined = 'joined';
    case Declined = 'declined';
    case Removed = 'removed';
}
