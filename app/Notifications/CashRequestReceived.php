<?php

namespace App\Notifications;

use App\Models\CashRequest;

/**
 * Sent to the event owner when a member says they've handed over cash.
 */
class CashRequestReceived extends AppNotification
{
    public function __construct(private CashRequest $cashRequest) {}

    public function type(): string
    {
        return 'cash_request';
    }

    public function title(): string
    {
        return 'Cash payment to confirm';
    }

    public function body(): string
    {
        $amount = number_format((float) $this->cashRequest->amount);

        return "{$this->cashRequest->user->name} paid ₹{$amount} in cash for {$this->cashRequest->event->name}. Please confirm.";
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->cashRequest->event_id,
            'cash_request_id' => $this->cashRequest->id,
        ];
    }
}
