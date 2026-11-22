<?php

namespace Tests\Feature;

use App\Enums\MemberStatus;
use App\Enums\TokenAbility;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InvitationReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The home screen API and the endpoints it is built from:
 * /home, /events, /invitations and /notifications.
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['first_name' => 'Demo', 'last_name' => 'User']);
    }

    public function test_home_returns_transaction_totals_events_and_invitations_in_one_response(): void
    {
        $owned = $this->eventOwnedBy($this->me, ['name' => 'Durga Puja', 'start_date' => now()->addDays(10)]);
        $joined = $this->eventJoinedBy($this->me, ['name' => 'Kali Puja', 'start_date' => now()->addDays(5)]);
        $invitation = $this->invitationFor($this->me);
        $this->eventOwnedBy(User::factory()->create()); // someone else's event: not listed

        $this->pay($this->me, $joined, 701);
        $this->pay(User::factory()->create(), $owned, 1001);

        $this->signIn($this->me);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('transactions.received', 1001)
            ->assertJsonPath('transactions.sent', 701)
            ->assertJsonPath('transactions.total', 1702)
            ->assertJsonMissingPath('wallet')
            ->assertJsonCount(1, 'events.owned')
            ->assertJsonPath('events.owned.0.id', $owned->id)
            ->assertJsonPath('events.owned.0.my_role', 'owner')
            ->assertJsonPath('events.owned.0.total_collected', 1001)
            ->assertJsonPath('events.owned.0.member_count', 1)
            ->assertJsonCount(1, 'events.joined')
            ->assertJsonPath('events.joined.0.id', $joined->id)
            ->assertJsonPath('events.joined.0.my_role', 'member')
            ->assertJsonPath('events.joined.0.my_contribution', 701)
            ->assertJsonPath('events.joined.0.contributor_count', 1)
            ->assertJsonPath('events.joined.0.owner.name', $joined->owner->name)
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.id', $invitation->id)
            ->assertJsonStructure(['invitations' => [['id', 'created_at', 'invited_by' => ['id', 'name'], 'event' => ['id', 'name', 'start_date', 'proposed_amount', 'member_count']]]]);
    }

    public function test_home_runs_a_fixed_number_of_queries(): void
    {
        $this->signIn($this->me);

        foreach (range(1, 2) as $i) {
            $this->eventJoinedBy($this->me);
            $this->invitationFor($this->me);
        }
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->getJson('/api/v1/home')->assertOk();
        $withTwo = $queries;

        foreach (range(1, 5) as $i) {
            $this->eventJoinedBy($this->me);
            $this->invitationFor($this->me);
        }
        $queries = 0;
        $this->getJson('/api/v1/home')->assertOk();

        $this->assertSame($withTwo, $queries, 'Home must not run extra queries per event or invitation.');
    }

    public function test_home_requires_authentication(): void
    {
        $this->getJson('/api/v1/home')->assertUnauthorized();
    }

    public function test_events_are_listed_by_scope(): void
    {
        $owned = $this->eventOwnedBy($this->me);
        $joined = $this->eventJoinedBy($this->me);
        $this->signIn($this->me);

        $this->getJson('/api/v1/events?scope=owned')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->id);
        $this->getJson('/api/v1/events?scope=joined')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $joined->id);
        $this->getJson('/api/v1/events?scope=everything')->assertUnprocessable()->assertJsonValidationErrors('scope');
    }

    public function test_events_exclude_pending_and_declined_memberships(): void
    {
        $this->invitationFor($this->me);
        EventMember::factory()->declined()->create(['user_id' => $this->me->id, 'phone' => $this->me->phone]);
        $this->signIn($this->me);

        $this->getJson('/api/v1/events?scope=joined')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_accepting_an_invitation_joins_the_event_and_notifies_the_owner(): void
    {
        $invitation = $this->invitationFor($this->me);
        $this->signIn($this->me);

        $this->postJson("/api/v1/invitations/{$invitation->id}/accept")->assertOk();

        $this->assertSame(MemberStatus::Joined, $invitation->fresh()->status);
        $this->assertNotNull($invitation->fresh()->added_at);
        $this->getJson('/api/v1/events?scope=joined')->assertJsonPath('data.0.id', $invitation->event_id);
        $this->getJson('/api/v1/invitations')->assertJsonCount(0, 'data');

        $owner = $invitation->event->owner;
        $this->assertSame('invitation_accepted', $owner->notifications()->first()->type);

        // Already answered: can't accept again.
        $this->postJson("/api/v1/invitations/{$invitation->id}/accept")->assertNotFound();
    }

    public function test_declining_an_invitation(): void
    {
        $invitation = $this->invitationFor($this->me);
        $this->signIn($this->me);

        $this->postJson("/api/v1/invitations/{$invitation->id}/decline")->assertOk();

        $this->assertSame(MemberStatus::Declined, $invitation->fresh()->status);
        $this->getJson('/api/v1/invitations')->assertJsonCount(0, 'data');
    }

    public function test_users_cannot_answer_someone_elses_invitation(): void
    {
        $invitation = $this->invitationFor(User::factory()->create());
        $this->signIn($this->me);

        $this->postJson("/api/v1/invitations/{$invitation->id}/accept")->assertNotFound();
        $this->postJson("/api/v1/invitations/{$invitation->id}/decline")->assertNotFound();
        $this->assertSame(MemberStatus::Invited, $invitation->fresh()->status);
    }

    public function test_registering_claims_invitations_sent_to_the_phone(): void
    {
        $invitation = EventMember::factory()->unregistered()->create(['phone' => '9811111111']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Person',
            'phone' => '9811111111',
            'password' => 'secret1',
            'password_confirmation' => 'secret1',
        ])->assertCreated();

        $user = User::where('phone', '9811111111')->firstOrFail();
        $this->assertSame($user->id, $invitation->fresh()->user_id);

        $this->signIn($user);
        $this->getJson('/api/v1/home')->assertJsonPath('invitations.0.id', $invitation->id);
        $this->getJson('/api/v1/notifications')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'invitation')
            ->assertJsonPath('data.0.data.invitation_id', $invitation->id)
            ->assertJsonPath('data.0.read_at', null);
    }

    public function test_notifications_can_be_marked_read(): void
    {
        $invitation = $this->invitationFor($this->me);
        $this->me->notify(new InvitationReceived($invitation->load('event', 'inviter')));
        $id = $this->me->notifications()->first()->id;
        $this->signIn($this->me);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'New invitation')
            ->assertJsonPath('data.0.body', "{$invitation->inviter->name} invited you to {$invitation->event->name}");

        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->assertNotNull($this->me->notifications()->first()->read_at);

        $someoneElse = User::factory()->create();
        $someoneElse->notify(new InvitationReceived($invitation));
        $this->postJson('/api/v1/notifications/'.$someoneElse->notifications()->first()->id.'/read')->assertNotFound();
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eventOwnedBy(User $owner, array $attributes = []): Event
    {
        $event = Event::factory()->for($owner, 'owner')->create($attributes);
        EventMember::factory()->owner()->for($event)->create(['user_id' => $owner->id, 'phone' => $owner->phone]);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eventJoinedBy(User $member, array $attributes = []): Event
    {
        $event = $this->eventOwnedBy(User::factory()->create(), $attributes);
        EventMember::factory()->for($event)->create(['user_id' => $member->id, 'phone' => $member->phone]);

        return $event;
    }

    private function invitationFor(User $user): EventMember
    {
        $event = $this->eventOwnedBy(User::factory()->create());

        return EventMember::factory()->invited()->for($event)->create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'invited_by' => $event->owner_id,
        ]);
    }

    private function pay(User $payer, Event $event, int $amount): Contribution
    {
        $payment = Payment::factory()->paid()->create(['event_id' => $event->id, 'user_id' => $payer->id, 'amount' => $amount]);

        return Contribution::factory()->create(['payment_id' => $payment->id]);
    }
}
