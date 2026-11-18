<?php

namespace App\Notifications;

use App\Models\Contribution;

/**
 * Sent to the event owner when a contribution is captured.
 */
class PaymentReceived extends AppNotification
{
    public function __construct(private Contribution $contribution) {}

    public function type(): string
    {
        return 'payment_received';
    }

    public function title(): string
    {
        return 'Payment received';
    }

    public function body(): string
    {
        $amount = number_format((float) $this->contribution->amount);

        return "{$this->contribution->user->name} paid ₹{$amount} for {$this->contribution->event->name}";
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->contribution->event_id,
            'contribution_id' => $this->contribution->id,
        ];
    }
}
