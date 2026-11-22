<?php

namespace App\Http\Resources;

use App\Models\CashRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRequest
 */
class CashRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'member' => new UserSummaryResource($this->whenLoaded('user')),
            'requested_by' => new UserSummaryResource($this->whenLoaded('requester')),
            'amount' => (float) $this->amount,
            'note' => $this->note,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
