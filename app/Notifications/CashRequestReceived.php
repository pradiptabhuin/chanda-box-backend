<?php

namespace App\Notifications;

use App\Enums\PaymentMethod;
use App\Models\CashRequest;

/**
 * Sent to the event owner when a member says they've handed over cash or paid their UPI ID.
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
        return $this->cashRequest->method === PaymentMethod::Upi ? 'UPI payment to confirm' : 'Cash payment to confirm';
    }

    public function body(): string
    {
        $amount = number_format((float) $this->cashRequest->amount);

        $name = $this->cashRequest->user->name;
        $event = $this->cashRequest->event->name;

        if ($this->cashRequest->method === PaymentMethod::Upi) {
            return "{$name} paid ₹{$amount} by UPI for {$event} (UTR {$this->cashRequest->utr}). Check your UPI app and confirm.";
        }

        return "{$name} paid ₹{$amount} in cash for {$event}. Please confirm.";
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->cashRequest->event_id,
            'cash_request_id' => $this->cashRequest->id,
            'method' => $this->cashRequest->method->value,
        ];
    }
}
