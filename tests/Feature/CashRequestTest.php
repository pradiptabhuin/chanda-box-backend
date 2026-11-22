<?php

namespace Tests\Feature;

use App\Enums\CashRequestStatus;
use App\Enums\EventStatus;
use App\Enums\PaymentMethod;
use App\Enums\TokenAbility;
use App\Models\CashRequest;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use App\Notifications\CashRequestReceived;
use App\Notifications\CashRequestReviewed;
use App\Notifications\PaidOnYourBehalf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cash contributions: a member asks, the owner confirms; the owner can record cash directly.
 */
class CashRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->owner = User::factory()->create(['first_name' => 'Ananya', 'last_name' => 'Roy']);
        $this->member = User::factory()->create(['first_name' => 'Arijit', 'last_name' => 'Sen']);
        $this->event = Event::factory()->for($this->owner, 'owner')->create(['name' => 'Kali Puja']);
        EventMember::factory()->owner()->for($this->event)->create(['user_id' => $this->owner->id, 'phone' => $this->owner->phone]);
        EventMember::factory()->for($this->event)->create(['user_id' => $this->member->id, 'phone' => $this->member->phone]);
    }

    public function test_a_member_asks_the_owner_to_confirm_cash(): void
    {
        $this->signIn($this->member);

        $this->postJson($this->url(), ['amount' => 1001, 'note' => 'Gave it at the pandal'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 1001)
            ->assertJsonPath('data.member.name', 'Arijit Sen')
            ->assertJsonPath('data.requested_by.name', 'Arijit Sen')
            ->assertJsonPath('data.note', 'Gave it at the pandal');

        Notification::assertSentTo($this->owner, CashRequestReceived::class);
        $this->assertDatabaseCount('contributions', 0);

        // One open request at a time.
        $this->postJson($this->url(), ['amount' => 500])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_the_owner_sees_every_pending_request_and_a_member_only_their_own(): void
    {
        $other = User::factory()->create();
        EventMember::factory()->for($this->event)->create(['user_id' => $other->id, 'phone' => $other->phone]);
        $mine = $this->pending($this->member);
        $this->pending($other);
        CashRequest::factory()->rejected()->for($this->event)->create(['user_id' => $this->member->id]);

        $this->signIn($this->owner);
        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');

        $this->signIn($this->member);
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        $this->signIn(User::factory()->create());
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_approving_counts_the_cash(): void
    {
        $request = $this->pending($this->member, 701);
        $this->signIn($this->owner);

        $this->postJson("/api/v1/cash-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/cash-requests/{$request->id}/approve")->assertUnprocessable();

        $this->assertDatabaseHas('contributions', [
            'cash_request_id' => $request->id,
            'payment_id' => null,
            'user_id' => $this->member->id,
            'paid_by' => null,
            'method' => PaymentMethod::Cash->value,
            'payment_ref' => "CASH-{$request->id}",
        ]);
        $this->assertDatabaseHas('cash_requests', ['id' => $request->id, 'reviewed_by' => $this->owner->id]);
        Notification::assertSentTo($this->member, CashRequestReviewed::class);
        Notification::assertNotSentTo($this->member, PaidOnYourBehalf::class);

        $this->getJson("/api/v1/events/{$this->event->id}")
            ->assertJsonPath('data.total_collected', 701)
            ->assertJsonPath('data.contributor_count', 1);
        $this->getJson("/api/v1/events/{$this->event->id}/contributions")->assertJsonPath('data.0.method', 'cash');
    }

    public function test_the_owner_can_reject_with_a_reason(): void
    {
        $request = $this->pending($this->member);
        $this->signIn($this->owner);

        $this->postJson("/api/v1/cash-requests/{$request->id}/reject", ['reason' => 'Not received yet'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Not received yet');

        $this->assertDatabaseCount('contributions', 0);
        Notification::assertSentTo($this->member, CashRequestReviewed::class);
    }

    public function test_only_the_owner_reviews_and_only_the_requester_cancels(): void
    {
        $request = $this->pending($this->member);

        $this->signIn($this->member);
        $this->postJson("/api/v1/cash-requests/{$request->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/cash-requests/{$request->id}/reject")->assertForbidden();

        $this->signIn($this->owner);
        $this->postJson("/api/v1/cash-requests/{$request->id}/cancel")->assertForbidden();

        $this->signIn($this->member);
        $this->postJson("/api/v1/cash-requests/{$request->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->signIn($this->owner);
        $this->postJson("/api/v1/cash-requests/{$request->id}/approve")->assertUnprocessable();
    }

    public function test_the_owner_records_their_own_cash_directly(): void
    {
        $this->signIn($this->owner);

        $this->postJson($this->url(), ['amount' => 2001])->assertCreated()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('contributions', ['user_id' => $this->owner->id, 'paid_by' => null, 'amount' => 2001]);
        Notification::assertNothingSent();
    }

    public function test_the_owner_can_pay_cash_on_behalf_of_a_member(): void
    {
        $this->signIn($this->owner);

        $this->postJson($this->url(), ['amount' => 1001, 'member_id' => $this->member->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.member.name', 'Arijit Sen')
            ->assertJsonPath('data.requested_by.name', 'Ananya Roy');

        $this->assertDatabaseHas('contributions', [
            'user_id' => $this->member->id,
            'paid_by' => $this->owner->id,
            'method' => PaymentMethod::Cash->value,
        ]);
        Notification::assertSentTo($this->member, PaidOnYourBehalf::class);

        $this->getJson("/api/v1/events/{$this->event->id}/members")
            ->assertJsonPath('data.1.user_id', $this->member->id)
            ->assertJsonPath('data.1.total_contributed', 1001);
    }

    public function test_members_cannot_pay_for_others_and_closed_events_take_no_cash(): void
    {
        $this->signIn($this->member);
        $this->postJson($this->url(), ['amount' => 501, 'member_id' => $this->owner->id])->assertForbidden();

        $this->event->update(['status' => EventStatus::Closed]);
        $this->postJson($this->url(), ['amount' => 501])->assertForbidden();
    }

    public function test_a_request_still_pending_when_the_event_closes_can_be_approved(): void
    {
        $request = $this->pending($this->member);
        $this->event->update(['status' => EventStatus::Closed]);
        $this->signIn($this->owner);

        $this->postJson("/api/v1/cash-requests/{$request->id}/approve")->assertOk();
        $this->assertSame(CashRequestStatus::Approved, $request->refresh()->status);
    }

    private function pending(User $member, int $amount = 501): CashRequest
    {
        return CashRequest::factory()->for($this->event)->create(['user_id' => $member->id, 'amount' => $amount]);
    }

    private function url(): string
    {
        return "/api/v1/events/{$this->event->id}/cash-requests";
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }
}
