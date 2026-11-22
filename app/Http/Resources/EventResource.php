<?php

namespace App\Http\Resources;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\EventSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event as seen by the requesting user. The totals come from EventRepository's
 * subqueries (total_collected, member_count, contributor_count, my_contribution).
 * `my_status` / `invitation_id` are only on the detail response (EventRepository::findForUser).
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'location' => $this->location,
            'curriculum' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn (EventSchedule $item) => [
                'title' => $item->title,
                'date' => $item->scheduled_at->toIso8601ZuluString(),
                'description' => $item->description,
            ])),
            'proposed_amount' => (float) $this->proposed_amount,
            'target_amount' => $this->target_amount === null ? null : (float) $this->target_amount,
            'start_date' => $this->start_date->toIso8601ZuluString(),
            'end_date' => $this->end_date?->toIso8601ZuluString(),
            'status' => $this->status,
            'owner' => new UserSummaryResource($this->whenLoaded('owner')),
            'total_collected' => (float) ($this->total_collected ?? 0),
            'member_count' => (int) ($this->member_count ?? 0),
            'contributor_count' => (int) ($this->contributor_count ?? 0),
            'my_contribution' => (float) ($this->my_contribution ?? 0),
            'my_role' => $this->owner_id === $request->user()?->id ? MemberRole::Owner : MemberRole::Member,
            'my_status' => $this->whenHas('my_status'),
            'invitation_id' => $this->when(
                $this->my_status === MemberStatus::Invited->value,
                fn () => (int) $this->my_member_id,
            ),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
