<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\TokenAbility;
use App\Models\CashRequest;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use App\Notifications\CashRequestReceived;
use App\Notifications\CashRequestReviewed;
use App\Notifications\PaidOnYourBehalf;
use App\Notifications\PaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Direct UPI: members pay the organiser's UPI ID from their own app, send the UTR,
 * and the organiser confirms it. The money never passes through the platform.
 */
class UpiPaymentTest extends TestCase
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
        $this->event = Event::factory()->for($this->owner, 'owner')->create([
            'name' => 'Kali Puja',
            'upi_id' => 'kalipuja@okaxis',
            'upi_payee_name' => 'Kali Puja Committee',
        ]);
        EventMember::factory()->owner()->for($this->event)->create(['user_id' => $this->owner->id, 'phone' => $this->owner->phone]);
        EventMember::factory()->for($this->event)->create(['user_id' => $this->member->id, 'phone' => $this->member->phone]);
    }

    public function test_the_organiser_sets_a_upi_id_on_the_event(): void
    {
        $this->signIn($this->owner);

        $this->postJson('/api/v1/events', [
            'name' => 'Saraswati Puja',
            'proposed_amount' => 501,
            'start_date' => now()->addWeek()->toIso8601String(),
            'upi_id' => '  Ananya.Roy@OKSBI ',
        ])
            ->assertCreated()
            ->assertJsonPath('data.accepts_upi', true)
            ->assertJsonPath('data.upi.upi_id', 'ananya.roy@oksbi')
            ->assertJsonPath('data.upi.payee_name', 'Ananya Roy')
            ->assertJsonPath('data.upi.uri', 'upi://pay?pa=ananya.roy%40oksbi&pn=Ananya%20Roy&cu=INR&tn=Chanda%20Saraswati%20Puja');

        $this->postJson('/api/v1/events', [
            'name' => 'Saraswati Puja',
            'proposed_amount' => 501,
            'start_date' => now()->addWeek()->toIso8601String(),
            'upi_id' => 'not-a-upi-id',
        ])->assertUnprocessable()->assertJsonValidationErrors('upi_id');
    }

    public function test_members_see_the_payee_before_paying(): void
    {
        $this->signIn($this->member);

        $this->getJson("/api/v1/events/{$this->event->id}")
            ->assertOk()
            ->assertJsonPath('data.accepts_upi', true)
            ->assertJsonPath('data.upi.payee_name', 'Kali Puja Committee');

        $this->event->update(['upi_id' => null]);

        $this->getJson("/api/v1/events/{$this->event->id}")
            ->assertJsonPath('data.accepts_upi', false)
            ->assertJsonPath('data.upi', null);
    }

    public function test_a_member_reports_a_upi_payment_and_the_owner_confirms_it(): void
    {
        $this->signIn($this->member);

        $id = $this->postJson($this->url(), ['amount' => 1001, 'method' => 'upi', 'utr' => '4123 4567 8901'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.method', 'upi')
            ->assertJsonPath('data.utr', '412345678901')
            ->json('data.id');

        Notification::assertSentTo(
            $this->owner,
            CashRequestReceived::class,
            fn (CashRequestReceived $notification) => $notification->title() === 'UPI payment to confirm'
                && str_contains($notification->body(), 'UTR 412345678901'),
        );
        $this->assertDatabaseCount('contributions', 0);

        $this->signIn($this->owner);
        $this->postJson("/api/v1/cash-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('contributions', [
            'cash_request_id' => $id,
            'user_id' => $this->member->id,
            'method' => PaymentMethod::Upi->value,
            'payment_ref' => '412345678901',
        ]);
        Notification::assertSentTo(
            $this->member,
            CashRequestReviewed::class,
            fn (CashRequestReviewed $notification) => $notification->title() === 'UPI payment confirmed',
        );
        // The owner confirmed it themselves: no "payment received" push.
        Notification::assertNotSentTo($this->owner, PaymentReceived::class);

        $this->getJson("/api/v1/events/{$this->event->id}")->assertJsonPath('data.total_collected', 1001);
    }

    public function test_a_member_must_send_a_valid_utr(): void
    {
        $this->signIn($this->member);

        $this->postJson($this->url(), ['amount' => 501, 'method' => 'upi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('utr');
        $this->postJson($this->url(), ['amount' => 501, 'method' => 'upi', 'utr' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('utr');
        $this->postJson($this->url(), ['amount' => 501, 'utr' => '412345678901'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('utr');
        $this->postJson($this->url(), ['amount' => 501, 'method' => 'razorpay'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');
    }

    public function test_a_utr_cannot_be_claimed_twice_in_an_event(): void
    {
        CashRequest::factory()->upi()->approved()->for($this->event)->create([
            'user_id' => $this->owner->id,
            'utr' => '412345678901',
        ]);
        $this->signIn($this->member);

        $this->postJson($this->url(), ['amount' => 501, 'method' => 'upi', 'utr' => '412345678901'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('utr');

        // A rejected claim frees the UTR again.
        CashRequest::query()->update(['status' => 'rejected']);
        $this->postJson($this->url(), ['amount' => 501, 'method' => 'upi', 'utr' => '412345678901'])->assertCreated();
    }

    public function test_upi_needs_the_event_to_have_a_upi_id(): void
    {
        $this->event->update(['upi_id' => null]);
        $this->signIn($this->member);

        $this->postJson($this->url(), ['amount' => 501, 'method' => 'upi', 'utr' => '412345678901'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        // Cash still works.
        $this->postJson($this->url(), ['amount' => 501])->assertCreated()->assertJsonPath('data.method', 'cash');
    }

    public function test_the_owner_records_a_upi_payment_for_a_member_without_a_utr(): void
    {
        $this->signIn($this->owner);

        $id = $this->postJson($this->url(), ['amount' => 701, 'method' => 'upi', 'member_id' => $this->member->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->json('data.id');

        $this->assertDatabaseHas('contributions', [
            'user_id' => $this->member->id,
            'paid_by' => $this->owner->id,
            'method' => PaymentMethod::Upi->value,
            'payment_ref' => "UPI-{$id}",
        ]);
        Notification::assertSentTo(
            $this->member,
            PaidOnYourBehalf::class,
            fn (PaidOnYourBehalf $notification) => str_contains($notification->body(), 'recorded a UPI payment of ₹701'),
        );
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
