<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\MemberStatus;
use App\Enums\TokenAbility;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The organiser closes, reopens or cancels an event, and removes members or cancels invitations.
 */
class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Event $event;

    private EventMember $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create(['name' => 'Arijit Sen']);
        $this->event = Event::factory()->for($this->owner, 'owner')->create();
        EventMember::factory()->owner()->for($this->event)->create(['user_id' => $this->owner->id, 'phone' => $this->owner->phone]);
        $this->membership = EventMember::factory()->for($this->event)->create(['user_id' => $this->member->id, 'phone' => $this->member->phone]);
    }

    public function test_the_owner_can_close_and_reopen_collection(): void
    {
        $this->signIn($this->owner);

        $this->putJson($this->statusUrl(), ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.my_status', 'joined');
        $this->assertSame(EventStatus::Closed, $this->event->fresh()->status);

        $this->putJson($this->statusUrl(), ['status' => 'active'])->assertJsonPath('data.status', 'active');
        $this->putJson($this->statusUrl(), ['status' => 'finished'])->assertJsonValidationErrors('status');
    }

    public function test_cancelling_needs_an_empty_event_and_is_final(): void
    {
        $this->signIn($this->owner);
        $payment = Payment::factory()->paid()->create(['event_id' => $this->event->id, 'user_id' => $this->member->id]);
        $contribution = Contribution::factory()->create(['payment_id' => $payment->id]);

        $this->putJson($this->statusUrl(), ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Close it instead']);

        $contribution->delete();
        $this->putJson($this->statusUrl(), ['status' => 'cancelled'])->assertJsonPath('data.status', 'cancelled');
        $this->putJson($this->statusUrl(), ['status' => 'active'])->assertJsonValidationErrors(['status' => "can't be reopened"]);

        // No more edits or invitations once cancelled.
        $this->postJson("/api/v1/events/{$this->event->id}/invitations", ['phones' => ['9811111111']])->assertForbidden();
        $this->putJson("/api/v1/events/{$this->event->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_only_the_owner_manages_the_event(): void
    {
        $this->signIn($this->member);

        $this->putJson($this->statusUrl(), ['status' => 'closed'])->assertForbidden();
        $this->deleteJson($this->memberUrl($this->membership->id))->assertForbidden();
    }

    public function test_the_owner_removes_a_member_who_then_loses_access(): void
    {
        $this->signIn($this->owner);

        $this->getJson("/api/v1/events/{$this->event->id}/members")->assertJsonPath('data.1.id', $this->membership->id);
        $this->deleteJson($this->memberUrl($this->membership->id))->assertOk();

        $this->membership->refresh();
        $this->assertSame(MemberStatus::Removed, $this->membership->status);
        $this->assertNotNull($this->membership->left_at);
        $this->getJson("/api/v1/events/{$this->event->id}/members")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/events/{$this->event->id}")->assertJsonPath('data.member_count', 1);

        $this->signIn($this->member);
        $this->getJson("/api/v1/events/{$this->event->id}")->assertForbidden();
        $this->getJson('/api/v1/events?scope=joined')->assertJsonCount(0, 'data');
    }

    public function test_the_owner_cancels_an_invitation_and_can_invite_again(): void
    {
        $invitee = User::factory()->create();
        $invitation = EventMember::factory()->invited()->for($this->event)->create(['user_id' => $invitee->id, 'phone' => $invitee->phone]);
        $this->signIn($this->owner);

        $this->deleteJson($this->memberUrl($invitation->id))->assertOk();
        $this->assertSame(MemberStatus::Removed, $invitation->fresh()->status);

        $this->signIn($invitee);
        $this->getJson('/api/v1/invitations')->assertJsonCount(0, 'data');

        $this->signIn($this->owner);
        $this->postJson("/api/v1/events/{$this->event->id}/invitations", ['phones' => [$invitee->phone]])
            ->assertJsonPath('invited', 1);
    }

    public function test_the_owner_row_and_unknown_rows_cannot_be_removed(): void
    {
        $ownerRow = $this->event->members()->where('user_id', $this->owner->id)->value('id');
        $otherEventsMember = EventMember::factory()->create();
        $this->signIn($this->owner);

        $this->deleteJson($this->memberUrl($ownerRow))->assertJsonValidationErrors('member');
        $this->deleteJson($this->memberUrl($otherEventsMember->id))->assertNotFound();

        $this->deleteJson($this->memberUrl($this->membership->id))->assertOk();
        $this->deleteJson($this->memberUrl($this->membership->id))->assertNotFound();
    }

    private function statusUrl(): string
    {
        return "/api/v1/events/{$this->event->id}/status";
    }

    private function memberUrl(int $memberId): string
    {
        return "/api/v1/events/{$this->event->id}/members/{$memberId}";
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }
}
