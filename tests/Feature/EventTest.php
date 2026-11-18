<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\TokenAbility;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Creating, viewing and editing an event, plus its members and contributions.
 */
class EventTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['first_name' => 'Demo', 'last_name' => 'User']);
    }

    public function test_creating_an_event_makes_the_creator_its_owner_and_first_member(): void
    {
        $this->signIn($this->me);

        $response = $this->postJson('/api/v1/events', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sarbojanin Durga Puja')
            ->assertJsonPath('data.location', 'Netaji Park, Salt Lake')
            ->assertJsonPath('data.proposed_amount', 1001)
            ->assertJsonPath('data.target_amount', 150000)
            ->assertJsonPath('data.start_date', '2026-12-09T00:00:00Z')
            ->assertJsonPath('data.end_date', '2026-12-13T00:00:00Z')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.owner.id', $this->me->id)
            ->assertJsonPath('data.owner.name', 'Demo User')
            ->assertJsonPath('data.my_role', 'owner')
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.total_collected', 0)
            ->assertJsonCount(2, 'data.curriculum')
            ->assertJsonPath('data.curriculum.0.title', 'Bodhon')
            ->assertJsonPath('data.curriculum.1.title', 'Ashtami Anjali');

        $event = Event::findOrFail($response->json('data.id'));
        $this->assertSame($this->me->id, $event->owner_id);
        $this->assertDatabaseHas('event_members', [
            'event_id' => $event->id,
            'user_id' => $this->me->id,
            'phone' => $this->me->phone,
            'role' => MemberRole::Owner->value,
            'status' => MemberStatus::Joined->value,
        ]);

        // It now shows up on the owner's home screen.
        $this->getJson('/api/v1/home')->assertJsonPath('events.owned.0.id', $event->id);
    }

    public function test_dates_keep_the_same_instant_whatever_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);
        date_default_timezone_set('Asia/Kolkata');
        $this->signIn($this->me);

        try {
            $id = $this->postJson('/api/v1/events', $this->payload())->assertCreated()->json('data.id');

            $this->assertSame('2026-12-09 05:30:00', Event::findOrFail($id)->getRawOriginal('start_date'));
            $this->getJson("/api/v1/events/{$id}")->assertJsonPath('data.start_date', '2026-12-09T00:00:00Z');
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_curriculum_and_optional_fields_can_be_left_out(): void
    {
        $this->signIn($this->me);

        $this->postJson('/api/v1/events', [
            'name' => 'Lakshmi Puja',
            'proposed_amount' => 301,
            'start_date' => '2026-12-01T00:00:00Z',
        ])
            ->assertCreated()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.target_amount', null)
            ->assertJsonPath('data.end_date', null)
            ->assertJsonCount(0, 'data.curriculum');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_events_are_rejected(array $overrides, string $field): void
    {
        $this->signIn($this->me);

        $this->postJson('/api/v1/events', [...$this->payload(), ...$overrides])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('events', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'zero amount' => [['proposed_amount' => 0], 'proposed_amount'],
            'too many decimals' => [['proposed_amount' => 10.555], 'proposed_amount'],
            'target below proposed' => [['target_amount' => 500], 'target_amount'],
            'end before start' => [['end_date' => '2026-12-01T00:00:00Z'], 'end_date'],
            'bad start date' => [['start_date' => 'next week'], 'start_date'],
            'curriculum item without title' => [['curriculum' => [['date' => '2026-12-10T00:00:00Z']]], 'curriculum.0.title'],
        ];
    }

    public function test_guests_cannot_create_events(): void
    {
        $this->postJson('/api/v1/events', $this->payload())->assertUnauthorized();
    }

    public function test_the_owner_can_edit_the_event_and_replace_its_curriculum(): void
    {
        $this->signIn($this->me);
        $id = $this->postJson('/api/v1/events', $this->payload())->json('data.id');

        $this->putJson("/api/v1/events/{$id}", [
            ...$this->payload(),
            'name' => 'Durga Puja 2026',
            'curriculum' => [['title' => 'Sindur Khela', 'date' => '2026-12-13T05:00:00Z']],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Durga Puja 2026')
            ->assertJsonCount(1, 'data.curriculum')
            ->assertJsonPath('data.curriculum.0.title', 'Sindur Khela');

        $this->assertDatabaseCount('event_schedules', 1);
        $this->assertDatabaseCount('event_members', 1);
    }

    public function test_only_the_owner_can_edit(): void
    {
        $event = $this->eventWithOwner(User::factory()->create());
        $this->join($this->me, $event);
        $this->signIn($this->me);

        // Forbidden even with an invalid body: authorization runs before validation.
        $this->putJson("/api/v1/events/{$event->id}", ['name' => ''])->assertForbidden();
        $this->putJson("/api/v1/events/{$event->id}", $this->payload())->assertForbidden();
    }

    public function test_an_event_can_be_viewed_by_its_owner_members_and_invitees_only(): void
    {
        $event = $this->eventWithOwner(User::factory()->create());

        $this->signIn($this->me);
        $this->getJson("/api/v1/events/{$event->id}")->assertForbidden();

        $invite = EventMember::factory()->invited()->for($event)->create(['user_id' => $this->me->id, 'phone' => $this->me->phone]);
        $this->getJson("/api/v1/events/{$event->id}")->assertOk()->assertJsonPath('data.my_role', 'member');
        $this->getJson("/api/v1/events/{$event->id}/members")->assertForbidden();
        $this->getJson("/api/v1/events/{$event->id}/contributions")->assertForbidden();

        $invite->update(['status' => MemberStatus::Joined]);
        $this->getJson("/api/v1/events/{$event->id}/members")->assertOk();
        $this->getJson("/api/v1/events/{$event->id}/contributions")->assertOk();

        $this->getJson('/api/v1/events/999999')->assertNotFound();
    }

    public function test_members_list_owner_first_with_their_totals(): void
    {
        $event = $this->eventWithOwner($this->me);
        $member = User::factory()->create(['first_name' => 'Arijit', 'last_name' => 'Sen']);
        $this->join($member, $event);
        EventMember::factory()->unregistered()->for($event)->create(['phone' => '9811111111']);
        EventMember::factory()->declined()->for($event)->create();
        $this->pay($member, $event, 1001);
        $this->pay($member, $event, 500);
        $this->signIn($this->me);

        $this->getJson("/api/v1/events/{$event->id}/members")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.user_id', $this->me->id)
            ->assertJsonPath('data.0.is_owner', true)
            ->assertJsonPath('data.0.total_contributed', 0)
            ->assertJsonPath('data.1.name', 'Arijit Sen')
            ->assertJsonPath('data.1.status', 'joined')
            ->assertJsonPath('data.1.total_contributed', 1501)
            ->assertJsonPath('data.2.user_id', null)
            ->assertJsonPath('data.2.name', '9811111111')
            ->assertJsonPath('data.2.status', 'invited');
    }

    public function test_contributions_are_listed_newest_first(): void
    {
        $event = $this->eventWithOwner($this->me);
        $older = $this->pay($this->me, $event, 501, now()->subDays(2));
        $newer = $this->pay($this->me, $event, 1001, now()->subDay());
        $this->signIn($this->me);

        $this->getJson("/api/v1/events/{$event->id}/contributions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.amount', 1001)
            ->assertJsonPath('data.0.user.name', 'Demo User')
            ->assertJsonPath('data.1.id', $older->id);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.total_collected', 1502)
            ->assertJsonPath('data.my_contribution', 1502)
            ->assertJsonPath('data.contributor_count', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'name' => 'Sarbojanin Durga Puja',
            'description' => 'Annual puja of our para.',
            'location' => 'Netaji Park, Salt Lake',
            'proposed_amount' => 1001,
            'target_amount' => 150000,
            'start_date' => '2026-12-09T00:00:00Z',
            'end_date' => '2026-12-13T00:00:00Z',
            'curriculum' => [
                ['title' => 'Bodhon', 'date' => '2026-12-09T12:30:00Z', 'description' => null],
                ['title' => 'Ashtami Anjali', 'date' => '2026-12-11T03:30:00Z', 'description' => 'Bring flowers.'],
            ],
        ];
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }

    private function eventWithOwner(User $owner): Event
    {
        $event = Event::factory()->for($owner, 'owner')->create();
        EventMember::factory()->owner()->for($event)->create(['user_id' => $owner->id, 'phone' => $owner->phone]);

        return $event;
    }

    private function join(User $user, Event $event): EventMember
    {
        return EventMember::factory()->for($event)->create(['user_id' => $user->id, 'phone' => $user->phone]);
    }

    private function pay(User $payer, Event $event, int $amount, ?\DateTimeInterface $paidAt = null): Contribution
    {
        $payment = Payment::factory()->paid()->create(['event_id' => $event->id, 'user_id' => $payer->id, 'amount' => $amount]);

        return Contribution::factory()->create(['payment_id' => $payment->id, 'paid_at' => $paidAt ?? now()]);
    }
}
