<?php

namespace App\Enums;

/**
 * Platform of a push-notification device token.
 */
enum DevicePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Web = 'web';
}
