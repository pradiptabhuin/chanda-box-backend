<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPushNotification;
use App\Models\User;
use App\Notifications\AppNotification;

/**
 * Sends an AppNotification to the user's phones as a push notification.
 *
 * The push runs after the HTTP response is sent, so the request isn't slowed down by
 * FCM and no queue worker is needed.
 */
class PushChannel
{
    public function send(object $notifiable, AppNotification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        SendPushNotification::dispatch(
            $notifiable->id,
            $notification->title(),
            $notification->body(),
            ['type' => $notification->type(), 'id' => $notification->id, ...$notification->payload()],
        )->afterResponse();
    }
}
