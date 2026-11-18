<?php

namespace App\Http\Resources;

use App\Models\EventMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending invitation received by the requesting user (an `event_members` row).
 *
 * @mixin EventMember
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => ($this->invited_at ?? $this->created_at)?->toIso8601ZuluString(),
            'invited_by' => new UserSummaryResource($this->whenLoaded('inviter')),
            'event' => [
                'id' => $this->event->id,
                'name' => $this->event->name,
                'description' => $this->event->description,
                'location' => $this->event->location,
                'start_date' => $this->event->start_date->toIso8601ZuluString(),
                'proposed_amount' => (float) $this->event->proposed_amount,
                'member_count' => (int) ($this->event->member_count ?? 0),
            ],
        ];
    }
}
