<?php

namespace App\Http\Resources;

use App\Models\Contribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contribution
 */
class ContributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            // Set when someone else (the owner) paid for this member.
            'paid_by' => $this->whenLoaded('payer', fn () => $this->payer ? new UserSummaryResource($this->payer) : null),
            'amount' => (float) $this->amount,
            'paid_at' => $this->paid_at->toIso8601ZuluString(),
            'method' => $this->method,
            'payment_ref' => $this->payment_ref,
            'note' => $this->note,
        ];
    }
}
