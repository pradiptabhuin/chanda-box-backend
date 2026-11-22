<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RouteStatus;
use App\Enums\TokenAbility;
use App\Enums\TransferStatus;
use App\Models\BankAccount;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\PaidOnYourBehalf;
use App\Notifications\PaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contributing to an event: Razorpay order + QR, Checkout verification, status polling and webhooks.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'test_secret';

    private const WEBHOOK_SECRET = 'webhook_secret';

    private User $owner;

    private User $member;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => self::KEY_SECRET,
            'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.route_enabled' => true,
            'payments.platform_fee' => 2,
            'payments.customer_fee_bearer' => false,
        ]);
        Notification::fake();
        Http::preventStrayRequests();

        $this->owner = User::factory()->create(['first_name' => 'Ananya', 'last_name' => 'Roy']);
        $this->member = User::factory()->create(['first_name' => 'Arijit', 'last_name' => 'Sen']);
        $this->event = Event::factory()->for($this->owner, 'owner')->create(['name' => 'Kali Puja']);
        EventMember::factory()->owner()->for($this->event)->create(['user_id' => $this->owner->id, 'phone' => $this->owner->phone]);
        EventMember::factory()->for($this->event)->create(['user_id' => $this->member->id, 'phone' => $this->member->phone]);
        BankAccount::factory()->for($this->owner)->active()->create(['razorpay_account_id' => 'acc_OWNER']);
    }

    public function test_a_member_gets_a_razorpay_order_and_upi_qr(): void
    {
        $this->fakeRazorpay();
        $this->signIn($this->member);

        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 1001])
            ->assertCreated()
            ->assertJsonPath('data.order_id', 'order_TEST')
            ->assertJsonPath('data.amount', 1001)
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.key_id', 'rzp_test_key')
            ->assertJsonPath('data.qr_image_url', 'https://rzp.io/i/qr')
            ->assertJsonPath('data.status', 'created');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/orders')
            && $request['amount'] === 100300
            && $request['notes']['event_id'] === $this->event->id);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payments/qr_codes')
            && $request['payment_amount'] === 100300
            && $request['usage'] === 'single_use'
            && $request['notes']['order_id'] === 'order_TEST');

        $this->assertDatabaseHas('payments', [
            'event_id' => $this->event->id,
            'user_id' => $this->member->id,
            'razorpay_order_id' => 'order_TEST',
            'razorpay_qr_id' => 'qr_TEST',
            'status' => PaymentStatus::Created->value,
        ]);
    }

    public function test_only_owner_and_joined_members_of_an_active_event_can_pay(): void
    {
        $this->fakeRazorpay();
        $outsider = User::factory()->create();
        $invitee = User::factory()->create();
        EventMember::factory()->invited()->for($this->event)->create(['user_id' => $invitee->id, 'phone' => $invitee->phone]);

        foreach ([$outsider, $invitee] as $user) {
            $this->signIn($user);
            $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501])->assertForbidden();
        }

        $this->signIn($this->owner);
        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501])->assertCreated();

        $this->event->update(['status' => EventStatus::Closed]);
        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501])->assertForbidden();
    }

    public function test_the_amount_is_validated(): void
    {
        $this->fakeRazorpay();
        $this->signIn($this->member);

        foreach ([0, 'abc', 10.555, 100_001] as $amount) {
            $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        Http::assertNothingSent();
    }

    public function test_a_gateway_failure_returns_a_retryable_error(): void
    {
        Http::fake(['*/orders' => Http::response(['error' => ['description' => 'Authentication failed']], 401)]);
        $this->signIn($this->member);

        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501])
            ->assertStatus(502)
            ->assertJsonPath('message', 'The payment service is unavailable right now. Please try again.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_verification_records_the_contribution_and_credits_the_owner_once(): void
    {
        $this->fakeRazorpay();
        $payment = $this->openPayment(1001);
        $this->signIn($this->member);
        $body = [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_CHECKOUT',
            'razorpay_signature' => $this->sign("{$payment->razorpay_order_id}|pay_CHECKOUT", self::KEY_SECRET),
        ];

        $this->postJson('/api/v1/payments/verify', $body)->assertOk()->assertJsonPath('status', 'paid');
        // A retry from the app (or a late webhook) must not pay twice.
        $this->postJson('/api/v1/payments/verify', $body)->assertOk();

        $this->assertDatabaseCount('contributions', 1);
        $this->assertDatabaseHas('contributions', [
            'payment_id' => $payment->id,
            'user_id' => $this->member->id,
            'method' => PaymentMethod::Razorpay->value,
            'payment_ref' => 'pay_CHECKOUT',
        ]);
        // The contribution (not the platform fee) goes to the organiser's linked account, once.
        $this->assertTransferredOnce($payment, 100100);
        Notification::assertSentToTimes($this->owner, PaymentReceived::class, 1);

        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'paid');
        $this->getJson("/api/v1/events/{$this->event->id}")
            ->assertJsonPath('data.total_collected', 1001)
            ->assertJsonPath('data.my_contribution', 1001);
    }

    public function test_an_invalid_signature_or_someone_elses_order_is_rejected(): void
    {
        $this->fakeRazorpay();
        $payment = $this->openPayment(501);

        $this->signIn($this->member);
        $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_X',
            'razorpay_signature' => 'forged',
        ])->assertUnprocessable()->assertJsonValidationErrors('razorpay_signature');

        $this->signIn($this->owner);
        $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_X',
            'razorpay_signature' => $this->sign("{$payment->razorpay_order_id}|pay_X", self::KEY_SECRET),
        ])->assertNotFound();
        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertNotFound();

        $this->assertDatabaseCount('contributions', 0);
    }

    public function test_status_turns_expired_once_the_qr_closes_unpaid(): void
    {
        $this->fakeRazorpay();
        $payment = $this->openPayment(501);
        $this->signIn($this->member);

        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'created');

        $this->travel(16)->minutes();
        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'expired');
    }

    public function test_a_qr_payment_is_captured_from_the_webhook_once(): void
    {
        $this->fakeRazorpay();
        $payment = $this->openPayment(701);
        $payload = $this->qrCreditedPayload($payment, 70300);

        $this->webhook($payload, 'evt_1')->assertOk();
        $this->webhook($payload, 'evt_1')->assertOk();
        $this->webhook($payload, 'evt_2')->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(PaymentMethod::UpiQr, $payment->method);
        $this->assertDatabaseCount('contributions', 1);
        $this->assertTransferredOnce($payment, 70100);
        $this->assertNotNull(WebhookEvent::where('event_id', 'evt_1')->value('processed_at'));
    }

    public function test_webhooks_with_a_bad_signature_or_wrong_amount_are_not_applied(): void
    {
        $payment = $this->openPayment(701);

        $this->postJson('/api/v1/webhooks/razorpay', $this->qrCreditedPayload($payment, 70300), [
            'X-Razorpay-Signature' => 'forged',
        ])->assertStatus(400);

        $this->webhook($this->qrCreditedPayload($payment, 100), 'evt_bad')->assertServerError();

        $this->assertSame(PaymentStatus::Created, $payment->refresh()->status);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertStringContainsString('Amount mismatch', WebhookEvent::where('event_id', 'evt_bad')->value('error'));
    }

    public function test_the_order_still_opens_when_razorpay_refuses_the_qr(): void
    {
        $this->fakeRazorpay(['*/payments/qr_codes' => Http::response(['error' => ['description' => 'QR codes not enabled']], 400)]);
        $this->signIn($this->member);

        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501])
            ->assertCreated()
            ->assertJsonPath('data.order_id', 'order_TEST')
            ->assertJsonPath('data.qr_image_url', null)
            ->assertJsonPath('data.qr_payload', null);

        $this->assertDatabaseHas('payments', ['razorpay_order_id' => 'order_TEST', 'razorpay_qr_id' => null]);
    }

    public function test_an_authorized_checkout_payment_is_captured_on_razorpay(): void
    {
        $this->fakeRazorpay([
            '*/payments/pay_AUTH' => Http::response(['id' => 'pay_AUTH', 'status' => 'authorized', 'amount' => 50300]),
        ]);
        $payment = $this->openPayment(501);
        $this->signIn($this->member);

        $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => 'pay_AUTH',
            'razorpay_signature' => $this->sign("{$payment->razorpay_order_id}|pay_AUTH", self::KEY_SECRET),
        ])->assertOk()->assertJsonPath('status', 'paid');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payments/pay_AUTH/capture')
            && $request['amount'] === 50300);
    }

    public function test_status_polling_picks_up_a_qr_payment_without_a_webhook(): void
    {
        $this->fakeRazorpay([
            '*/payments/qr_codes/*/payments' => Http::response(['items' => [
                ['id' => 'pay_QR', 'status' => 'captured', 'amount' => 70300],
            ]]),
        ]);
        $payment = $this->openPayment(701);
        $this->signIn($this->member);

        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('contributions', [
            'payment_id' => $payment->id,
            'method' => PaymentMethod::UpiQr->value,
            'payment_ref' => 'pay_QR',
        ]);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/capture'));
    }

    public function test_the_owner_can_pay_online_on_behalf_of_a_member(): void
    {
        $this->fakeRazorpay();
        $this->signIn($this->owner);

        $orderId = $this->postJson("/api/v1/events/{$this->event->id}/payments/order", [
            'amount' => 1001,
            'member_id' => $this->member->id,
        ])->assertCreated()->assertJsonPath('data.member_id', $this->member->id)->json('data.order_id');

        $this->postJson('/api/v1/payments/verify', [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => 'pay_CHECKOUT',
            'razorpay_signature' => $this->sign("{$orderId}|pay_CHECKOUT", self::KEY_SECRET),
        ])->assertOk();

        $this->assertDatabaseHas('contributions', [
            'user_id' => $this->member->id,
            'paid_by' => $this->owner->id,
            'amount' => 1001,
        ]);
        $this->assertTransferredOnce(Payment::where('razorpay_order_id', $orderId)->firstOrFail(), 100100);
        Notification::assertSentTo($this->member, PaidOnYourBehalf::class);
        Notification::assertNotSentTo($this->owner, PaymentReceived::class);

        $this->getJson("/api/v1/events/{$this->event->id}/contributions")
            ->assertJsonPath('data.0.user.name', 'Arijit Sen')
            ->assertJsonPath('data.0.paid_by.name', 'Ananya Roy');
    }

    public function test_only_the_owner_can_pay_for_joined_members(): void
    {
        $this->fakeRazorpay();
        $other = User::factory()->create();
        EventMember::factory()->for($this->event)->create(['user_id' => $other->id, 'phone' => $other->phone]);
        $outsider = User::factory()->create();

        $this->signIn($this->member);
        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501, 'member_id' => $other->id])
            ->assertForbidden();

        $this->signIn($this->owner);
        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 501, 'member_id' => $outsider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('member_id');
    }

    public function test_online_payments_need_route_and_an_active_organiser_account(): void
    {
        $this->fakeRazorpay();
        $this->signIn($this->member);
        $url = "/api/v1/events/{$this->event->id}";

        $this->getJson($url)->assertJsonPath('data.accepts_online', true);

        $this->owner->payoutAccount->update(['route_status' => RouteStatus::Pending]);
        $this->getJson($url)->assertJsonPath('data.accepts_online', false);
        $this->postJson("{$url}/payments/order", ['amount' => 501])
            ->assertUnprocessable()
            ->assertJsonPath('message', "The organiser hasn't set up online payments yet.");

        $this->owner->payoutAccount->update(['route_status' => RouteStatus::Active]);
        config(['payments.route_enabled' => false]);
        $this->getJson($url)->assertJsonPath('data.accepts_online', false);
        $this->postJson("{$url}/payments/order", ['amount' => 501])->assertUnprocessable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_the_quote_shows_the_platform_fee_and_free_upi(): void
    {
        $this->signIn($this->member);
        $url = "/api/v1/events/{$this->event->id}/payments/quote";

        $this->getJson("{$url}?amount=200")->assertOk()->assertExactJson([
            'contribution' => 200,
            'platform_fee' => 2,
            'upi_charges' => 0,
            'upi_total' => 202,
            'card_charges' => 0,
            'card_total' => 202,
            'charges_collected_by_razorpay' => false,
        ]);

        // With Razorpay's Customer Fee Bearer: an estimate of 2% + 18% GST on 202, never on UPI.
        config(['payments.customer_fee_bearer' => true]);
        $this->getJson("{$url}?amount=200")
            ->assertJsonPath('upi_charges', 0)
            ->assertJsonPath('card_charges', 4.77)
            ->assertJsonPath('card_total', 206.77)
            ->assertJsonPath('charges_collected_by_razorpay', true);

        $this->getJson("{$url}?amount=0")->assertUnprocessable();
        $this->signIn(User::factory()->create());
        $this->getJson("{$url}?amount=200")->assertForbidden();
    }

    public function test_the_order_charges_the_platform_fee_on_top(): void
    {
        $this->fakeRazorpay();
        $this->signIn($this->member);

        $this->postJson("/api/v1/events/{$this->event->id}/payments/order", ['amount' => 200])
            ->assertCreated()
            ->assertJsonPath('data.amount', 200)
            ->assertJsonPath('data.platform_fee', 2)
            ->assertJsonPath('data.total', 202);

        $this->assertDatabaseHas('payments', [
            'amount' => 200,
            'platform_fee' => 2,
            'charged_amount' => 202,
            'razorpay_linked_account_id' => 'acc_OWNER',
            'transfer_status' => 'pending',
        ]);
    }

    public function test_a_card_payment_including_razorpays_fee_is_still_accepted(): void
    {
        $this->fakeRazorpay();
        $payment = $this->openPayment(701);

        $this->webhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_CARD', 'order_id' => $payment->razorpay_order_id, 'amount' => 71960, 'fee' => 1660,
            ]]],
        ], 'evt_card')->assertOk();

        $this->assertTrue($payment->refresh()->isPaid());
        $this->assertTransferredOnce($payment, 70100);
    }

    public function test_a_failed_transfer_is_recorded_and_retried_by_the_scheduler_command(): void
    {
        $this->fakeRazorpay(['*/payments/*/transfers' => Http::sequence()
            ->push(['error' => ['description' => 'Linked account is not activated']], 400)
            ->push(['items' => [['id' => 'trf_RETRY']]]),
        ]);
        $payment = $this->openPayment(501);

        $this->webhook($this->qrCreditedPayload($payment, 50300), 'evt_t')->assertOk();

        $payment->refresh();
        $this->assertTrue($payment->isPaid());
        $this->assertSame(TransferStatus::Failed, $payment->transfer_status);
        $this->assertStringContainsString('Linked account is not activated', $payment->transfer_error);

        $this->artisan('payments:transfer')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(TransferStatus::Transferred, $payment->transfer_status);
        $this->assertSame('trf_RETRY', $payment->razorpay_transfer_id);
    }

    /**
     * Every Razorpay endpoint the API uses; `$overrides` are matched first.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeRazorpay(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/orders/*/payments' => Http::response(['items' => []]),
            '*/payments/qr_codes/*/payments' => Http::response(['items' => []]),
            '*/payments/qr_codes' => Http::response(['id' => 'qr_TEST', 'image_url' => 'https://rzp.io/i/qr']),
            '*/orders' => Http::response(['id' => 'order_TEST', 'status' => 'created']),
            '*/payments/*/capture' => Http::response(['status' => 'captured']),
            '*/payments/*/transfers' => Http::response(['entity' => 'collection', 'items' => [['id' => 'trf_TEST']]]),
            '*/payments/*' => Http::response(['id' => 'pay_CHECKOUT', 'status' => 'captured', 'amount' => 100100]),
        ]);
    }

    private function openPayment(int $amount): Payment
    {
        return Payment::factory()->create([
            'event_id' => $this->event->id,
            'user_id' => $this->member->id,
            'amount' => $amount,
            'platform_fee' => 2,
            'charged_amount' => $amount + 2,
            'razorpay_linked_account_id' => 'acc_OWNER',
            'transfer_status' => TransferStatus::Pending,
        ]);
    }

    private function assertTransferredOnce(Payment $payment, int $paise): void
    {
        $transfers = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/transfers'))->values();
        $this->assertCount(1, $transfers);
        $this->assertSame('acc_OWNER', $transfers[0][0]['transfers'][0]['account']);
        $this->assertSame($paise, $transfers[0][0]['transfers'][0]['amount']);

        $payment->refresh();
        $this->assertSame(TransferStatus::Transferred, $payment->transfer_status);
        $this->assertSame('trf_TEST', $payment->razorpay_transfer_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function qrCreditedPayload(Payment $payment, int $paise): array
    {
        return [
            'event' => 'qr_code.credited',
            'payload' => [
                'payment' => ['entity' => ['id' => 'pay_QR', 'amount' => $paise, 'method' => 'upi']],
                'qr_code' => ['entity' => ['id' => $payment->razorpay_qr_id]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload, string $deliveryId): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/razorpay', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $this->sign($body, self::WEBHOOK_SECRET),
            'HTTP_X_RAZORPAY_EVENT_ID' => $deliveryId,
        ], content: $body);
    }

    private function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, [TokenAbility::AccessApi->value]);
    }
}
