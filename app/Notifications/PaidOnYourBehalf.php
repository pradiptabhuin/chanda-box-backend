<?php

namespace App\Notifications;

use App\Models\Contribution;

/**
 * Sent to a member when the event owner records a contribution for them.
 */
class PaidOnYourBehalf extends AppNotification
{
    public function __construct(private Contribution $contribution) {}

    public function type(): string
    {
        return 'paid_on_behalf';
    }

    public function title(): string
    {
        return 'Contribution recorded';
    }

    public function body(): string
    {
        $amount = number_format((float) $this->contribution->amount);
        $how = $this->contribution->method->isOnline() ? 'paid' : 'recorded cash of';

        return "{$this->contribution->payer->name} {$how} ₹{$amount} for you in {$this->contribution->event->name}";
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->contribution->event_id,
            'contribution_id' => $this->contribution->id,
        ];
    }
}
