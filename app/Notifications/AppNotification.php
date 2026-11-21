<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app inbox notification, stored in the `notifications` table and polled by the app
 * (GET /notifications), and pushed to the user's phones via FCM. Subclasses only describe the message.
 */
abstract class AppNotification extends Notification
{
    use Queueable;

    /**
     * Short type the app switches on: invitation, invitation_accepted, payment_received, withdrawal.
     */
    abstract public function type(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    /**
     * IDs the app needs to open the related screen.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type();
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => $this->payload(),
        ];
    }
}
