<?php

namespace Tests\Feature;

use App\Core\Push\PushInterface;
use App\Enums\MemberStatus;
use App\Enums\TokenAbility;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The owner inviting phone numbers to an event, and the push notifications on both sides.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['first_name' => 'Ananya', 'last_name' => 'Roy', 'phone' => '9800000001']);
        $this->event = Event::factory()->for($this->owner, 'owner')->create(['name' => 'Kali Puja']);
        EventMember::factory()->owner()->for($this->event)->create(['user_id' => $this->owner->id, 'phone' => $this->owner->phone]);
    }

    public function test_owner_invites_registered_and_unregistered_numbers(): void
    {
        Bus::fake();
        $registered = User::factory()->create(['phone' => '9800000002']);
        $this->signIn($this->owner);

        $this->postJson($this->url(), ['phones' => ['+91 98000 00002', '09800000003']])
            ->assertOk()
            ->assertExactJson(['invited' => 2, 'skipped' => []]);

        $this->assertDatabaseHas('event_members', [
            'event_id' => $this->event->id,
            'phone' => '9800000002',
            'user_id' => $registered->id,
            'status' => MemberStatus::Invited->value,
            'invited_by' => $this->owner->id,
        ]);
        $this->assertDatabaseHas('event_members', [
            'event_id' => $this->event->id,
            'phone' => '9800000003',
            'user_id' => null,
            'status' => MemberStatus::Invited->value,
        ]);

        $notification = $registered->notifications()->sole();
        $this->assertSame('invitation', $notification->type);
        $this->assertSame('Ananya Roy invited you to Kali Puja', $notification->data['body']);

        Bus::assertDispatchedAfterResponse(SendPushNotification::class, fn (SendPushNotification $job) => $job->userId === $registered->id
            && $job->title === 'New invitation'
            && $job->body === 'Ananya Roy invited you to Kali Puja'
            && $job->data['type'] === 'invitation'
            && $job->data['event_id'] === $this->event->id
            && $job->data['id'] === $notification->id);
        Bus::assertDispatchedAfterResponseTimes(SendPushNotification::class, 1);

        // The invitee sees it in their list.
        $this->signIn($registered);
        $this->getJson('/api/v1/invitations')->assertJsonPath('data.0.event.id', $this->event->id);
    }

    public function test_skips_invalid_numbers_duplicates_the_owner_members_and_pending_invites(): void
    {
        $member = User::factory()->create(['phone' => '9800000002']);
        EventMember::factory()->for($this->event)->create(['user_id' => $member->id, 'phone' => $member->phone]);
        EventMember::factory()->unregistered()->for($this->event)->create(['phone' => '9800000003']);
        $this->signIn($this->owner);

        $this->postJson($this->url(), ['phones' => ['12345', '9800000001', '9800000002', '9800000003', '9800000004', '98000 00004']])
            ->assertOk()
            ->assertExactJson(['invited' => 1, 'skipped' => ['12345', '9800000004', '9800000001', '9800000002', '9800000003']]);

        $this->assertSame(4, $this->event->members()->count());
    }

    public function test_someone_who_declined_can_be_invited_again(): void
    {
        $invitee = User::factory()->create();
        $declined = EventMember::factory()->declined()->for($this->event)->create(['user_id' => $invitee->id, 'phone' => $invitee->phone]);
        $this->signIn($this->owner);

        $this->postJson($this->url(), ['phones' => [$invitee->phone]])->assertExactJson(['invited' => 1, 'skipped' => []]);

        $declined->refresh();
        $this->assertSame(MemberStatus::Invited, $declined->status);
        $this->assertNull($declined->responded_at);
        $this->assertSame($this->owner->id, $declined->invited_by);
        $this->assertSame('invitation', $invitee->notifications()->sole()->type);
    }

    public function test_only_the_owner_can_invite(): void
    {
        $member = User::factory()->create();
        EventMember::factory()->for($this->event)->create(['user_id' => $member->id, 'phone' => $member->phone]);
        $this->signIn($member);

        $this->postJson($this->url(), ['phones' => ['9800000009']])->assertForbidden();
        $this->assertDatabaseMissing('event_members', ['phone' => '9800000009']);
    }

    public function test_phones_are_required(): void
    {
        $this->signIn($this->owner);

        $this->postJson($this->url(), ['phones' => []])->assertUnprocessable()->assertJsonValidationErrors('phones');
        $this->postJson('/api/v1/events/999999/invitations', ['phones' => ['9800000009']])->assertNotFound();
    }

    public function test_accepting_pushes_to_the_owner(): void
    {
        Bus::fake();
        $invitee = User::factory()->create(['first_name' => 'Arijit', 'middle_name' => null, 'last_name' => 'Sen']);
        $invitation = EventMember::factory()->invited()->for($this->event)
            ->create(['user_id' => $invitee->id, 'phone' => $invitee->phone, 'invited_by' => $this->owner->id]);
        $this->signIn($invitee);

        $this->postJson("/api/v1/invitations/{$invitation->id}/accept")->assertOk();

        Bus::assertDispatchedAfterResponse(SendPushNotification::class, fn (SendPushNotification $job) => $job->userId === $this->owner->id
            && $job->title === 'Invitation accepted'
            && $job->body === 'Arijit Sen joined Kali Puja'
            && $job->data['type'] === 'invitation_accepted'
            && $job->data['user_id'] === $invitee->id);
    }

    public function test_push_job_sends_to_every_device_and_forgets_dead_tokens(): void
    {
        $user = User::factory()->create();
        DeviceToken::factory()->for($user)->create(['token' => 'phone-token']);
        DeviceToken::factory()->for($user)->create(['token' => 'dead-token']);
        DeviceToken::factory()->create(['token' => 'someone-else']);

        $push = new class implements PushInterface
        {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function send(array $tokens, string $title, string $body, array $data = []): array
            {
                $this->sent[] = compact('tokens', 'title', 'body', 'data');

                return ['dead-token'];
            }
        };

        (new SendPushNotification($user->id, 'Hi', 'There', ['type' => 'invitation']))->handle($push);

        $this->assertCount(1, $push->sent);
        $this->assertEqualsCanonicalizing(['phone-token', 'dead-token'], $push->sent[0]['tokens']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'phone-token']);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'dead-token']);
        $this->assertDatabaseHas('device_tokens', ['token' => 'someone-else']);
    }

    private function url(): string
    {
        return "/api/v1/events/{$this->event->id}/invitations";
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }
}
