<?php

namespace App\Notifications;

use App\Models\EventMember;
use App\Models\User;

class InvitationAccepted extends AppNotification
{
    public function __construct(private EventMember $invitation, private User $member) {}

    public function type(): string
    {
        return 'invitation_accepted';
    }

    public function title(): string
    {
        return 'Invitation accepted';
    }

    public function body(): string
    {
        return "{$this->member->name} joined {$this->invitation->event->name}";
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->invitation->event_id,
            'user_id' => $this->member->id,
        ];
    }
}
