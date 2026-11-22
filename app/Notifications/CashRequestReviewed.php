<?php

namespace App\Notifications;

use App\Enums\CashRequestStatus;
use App\Models\CashRequest;

/**
 * Sent to the member when the owner approves or rejects their cash payment.
 */
class CashRequestReviewed extends AppNotification
{
    public function __construct(private CashRequest $cashRequest) {}

    public function type(): string
    {
        return 'cash_request_reviewed';
    }

    public function title(): string
    {
        return $this->isApproved() ? 'Cash payment confirmed' : 'Cash payment not confirmed';
    }

    public function body(): string
    {
        $amount = number_format((float) $this->cashRequest->amount);
        $event = $this->cashRequest->event->name;

        if ($this->isApproved()) {
            return "Your cash payment of ₹{$amount} for {$event} was confirmed.";
        }

        return "Your cash payment of ₹{$amount} for {$event} was not confirmed"
            .($this->cashRequest->rejection_reason ? ": {$this->cashRequest->rejection_reason}" : '.');
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->cashRequest->event_id,
            'cash_request_id' => $this->cashRequest->id,
            'status' => $this->cashRequest->status->value,
        ];
    }

    private function isApproved(): bool
    {
        return $this->cashRequest->status === CashRequestStatus::Approved;
    }
}
