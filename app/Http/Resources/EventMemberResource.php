<?php

namespace App\Http\Resources;

use App\Models\EventMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `total_contributed` is set by EventRepository::members() from one grouped query.
 *
 * @mixin EventMember
 */
class EventMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->user?->name ?? $this->phone,
            'phone' => $this->phone,
            'status' => $this->status,
            'added_at' => ($this->added_at ?? $this->invited_at)?->toIso8601ZuluString(),
            'is_owner' => $this->isOwner(),
            'total_contributed' => (float) ($this->total_contributed ?? 0),
        ];
    }
}
