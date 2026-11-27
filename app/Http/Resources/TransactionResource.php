<?php

namespace App\Http\Resources;

use App\Enums\TransferStatus;
use App\Models\Contribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contribution seen from the requesting user's side (see TransactionInterface).
 *
 * @mixin Contribution
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()->id;
        $payer = $this->payer ?? $this->user;
        $organiser = $this->event->owner;
        $sent = $payer->id === $viewerId;
        $counterparty = match (true) {
            ! $sent => $payer,
            $organiser->id === $viewerId => $this->user, // the organiser paid on this member's behalf
            default => $organiser,
        };

        return [
            'id' => $this->id,
            'direction' => $sent ? 'sent' : 'received',
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'event' => ['id' => $this->event->id, 'name' => $this->event->name],
            'counterparty' => new UserSummaryResource($counterparty),
            'member' => $this->paid_by === null ? null : new UserSummaryResource($this->user),
            'platform_fee' => (float) ($this->payment?->platform_fee ?? 0),
            'payout_status' => $this->payoutStatus($sent),
            'paid_at' => $this->paid_at->toIso8601ZuluString(),
        ];
    }

    /**
     * Only for online money received: whether Route has moved it to the organiser's bank.
     */
    private function payoutStatus(bool $sent): ?string
    {
        if ($sent || ! $this->method->isViaRazorpay() || $this->payment?->transfer_status === null) {
            return null;
        }

        return match ($this->payment->transfer_status) {
            TransferStatus::Transferred => 'transferred',
            TransferStatus::Failed => 'failed',
            TransferStatus::Pending => 'pending',
        };
    }
}
