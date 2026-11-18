<?php

namespace App\Notifications;

use App\Models\EventMember;

class InvitationReceived extends AppNotification
{
    public function __construct(private EventMember $invitation) {}

    public function type(): string
    {
        return 'invitation';
    }

    public function title(): string
    {
        return 'New invitation';
    }

    public function body(): string
    {
        $inviter = $this->invitation->inviter?->name ?? 'Someone';

        return "{$inviter} invited you to {$this->invitation->event->name}";
    }

    public function payload(): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'event_id' => $this->invitation->event_id,
        ];
    }
}
