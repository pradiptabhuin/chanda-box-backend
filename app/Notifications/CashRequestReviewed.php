<?php

namespace App\Notifications;

use App\Enums\CashRequestStatus;
use App\Models\CashRequest;

/**
 * Sent to the member when the owner approves or rejects their cash or UPI payment.
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
        $kind = ucfirst($this->kind());

        return $this->isApproved() ? "{$kind} payment confirmed" : "{$kind} payment not confirmed";
    }

    public function body(): string
    {
        $amount = number_format((float) $this->cashRequest->amount);
        $event = $this->cashRequest->event->name;

        if ($this->isApproved()) {
            return "Your {$this->kind()} payment of ₹{$amount} for {$event} was confirmed.";
        }

        return "Your {$this->kind()} payment of ₹{$amount} for {$event} was not confirmed"
            .($this->cashRequest->rejection_reason ? ": {$this->cashRequest->rejection_reason}" : '.');
    }

    public function payload(): array
    {
        return [
            'event_id' => $this->cashRequest->event_id,
            'cash_request_id' => $this->cashRequest->id,
            'status' => $this->cashRequest->status->value,
            'method' => $this->cashRequest->method->value,
        ];
    }

    /**
     * "cash" or "UPI".
     */
    private function kind(): string
    {
        return $this->cashRequest->method->label();
    }

    private function isApproved(): bool
    {
        return $this->cashRequest->status === CashRequestStatus::Approved;
    }
}
