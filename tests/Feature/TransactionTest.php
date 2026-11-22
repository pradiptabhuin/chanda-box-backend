<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\TokenAbility;
use App\Enums\TransferStatus;
use App\Models\CashRequest;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /transactions: money received (as an organiser) and sent, with to-date totals.
 */
class TransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Event $myEvent;

    private Event $friendsEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['first_name' => 'Ananya', 'last_name' => 'Roy']);
        $this->friend = User::factory()->create(['first_name' => 'Arijit', 'last_name' => 'Sen']);
        $this->myEvent = Event::factory()->for($this->me, 'owner')->create(['name' => 'Durga Puja']);
        $this->friendsEvent = Event::factory()->for($this->friend, 'owner')->create(['name' => 'Kali Puja']);
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);
    }

    public function test_history_and_totals_cover_received_and_sent_money(): void
    {
        $received = $this->online($this->friend, $this->myEvent, 1001, TransferStatus::Transferred, now()->subDays(3));
        $sent = $this->online($this->me, $this->friendsEvent, 501, TransferStatus::Pending, now()->subDays(2));
        $cash = $this->cash($this->friend, $this->myEvent, 300, now()->subDay());
        $this->online($this->friend, $this->friendsEvent, 9999); // not mine at all

        $this->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('summary', ['received' => 1301, 'sent' => 501, 'total' => 1802, 'received_count' => 2, 'sent_count' => 1])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $cash->id)
            ->assertJsonPath('data.0.direction', 'received')
            ->assertJsonPath('data.0.method', 'cash')
            ->assertJsonPath('data.0.payout_status', null)
            ->assertJsonPath('data.0.platform_fee', 0)
            ->assertJsonPath('data.1.id', $sent->id)
            ->assertJsonPath('data.1.direction', 'sent')
            ->assertJsonPath('data.1.counterparty.name', 'Arijit Sen')
            ->assertJsonPath('data.1.event.name', 'Kali Puja')
            ->assertJsonPath('data.1.payout_status', null)
            ->assertJsonPath('data.2.id', $received->id)
            ->assertJsonPath('data.2.counterparty.name', 'Arijit Sen')
            ->assertJsonPath('data.2.platform_fee', 2)
            ->assertJsonPath('data.2.payout_status', 'transferred')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/transactions?filter=received')->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 1802);
        $this->getJson('/api/v1/transactions?filter=sent')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sent->id);
        $this->getJson('/api/v1/transactions?filter=everything')->assertJsonValidationErrors('filter');
    }

    public function test_paying_on_a_members_behalf_is_sent_money_shown_against_the_member(): void
    {
        $member = User::factory()->create(['first_name' => 'Rina', 'last_name' => 'Das']);
        $contribution = $this->cash($member, $this->myEvent, 700, paidBy: $this->me);
        $this->cash($this->me, $this->myEvent, 200); // the organiser's own share

        $this->getJson('/api/v1/transactions')
            ->assertJsonPath('summary.received', 0)
            ->assertJsonPath('summary.sent', 900)
            ->assertJsonPath('data.1.id', $contribution->id)
            ->assertJsonPath('data.1.direction', 'sent')
            ->assertJsonPath('data.1.counterparty.name', 'Rina Das')
            ->assertJsonPath('data.1.member.name', 'Rina Das');

        // For the member it's neither received nor sent: they didn't pay and don't organise.
        Sanctum::actingAs($member, [TokenAbility::AccessApi->value]);
        $this->getJson('/api/v1/transactions')->assertJsonPath('summary.total', 0)->assertJsonCount(0, 'data');
    }

    public function test_history_is_paginated_and_needs_sign_in(): void
    {
        foreach (range(1, 25) as $i) {
            $this->cash($this->friend, $this->myEvent, 10, now()->subMinutes($i));
        }

        $this->getJson('/api/v1/transactions')->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/transactions?filter=received&page=2')
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('summary.received_count', 25);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/transactions')->assertUnauthorized();
    }

    private function online(User $payer, Event $event, int $amount, TransferStatus $transfer = TransferStatus::Pending, $paidAt = null): Contribution
    {
        $payment = Payment::factory()->paid(PaymentMethod::Razorpay)->create([
            'event_id' => $event->id,
            'user_id' => $payer->id,
            'amount' => $amount,
            'platform_fee' => 2,
            'charged_amount' => $amount + 2,
            'transfer_status' => $transfer,
        ]);

        return Contribution::factory()->create(['payment_id' => $payment->id, 'paid_at' => $paidAt ?? now()]);
    }

    private function cash(User $member, Event $event, int $amount, $paidAt = null, ?User $paidBy = null): Contribution
    {
        $request = CashRequest::factory()->approved()->for($event)->create([
            'user_id' => $member->id,
            'requested_by' => ($paidBy ?? $member)->id,
            'amount' => $amount,
        ]);

        return Contribution::factory()->create([
            'payment_id' => null,
            'cash_request_id' => $request->id,
            'event_id' => $event->id,
            'user_id' => $member->id,
            'paid_by' => $paidBy?->id,
            'amount' => $amount,
            'method' => PaymentMethod::Cash,
            'payment_ref' => "CASH-{$request->id}",
            'paid_at' => $paidAt ?? now(),
        ]);
    }
}
