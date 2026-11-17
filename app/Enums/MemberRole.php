<?php

namespace App\Enums;

/**
 * Role of a user inside an event.
 */
enum MemberRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
