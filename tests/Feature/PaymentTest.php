<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
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
        ]);
        Notification::fake();

        $this->owner = User::factory()->create(['first_name' => 'Ananya', 'last_name' => 'Roy']);
        $this->member = User::factory()->create(['first_name' => 'Arijit', 'last_name' => 'Sen']);
        $this->event = Event::factory()->for($this->owner, 'owner')->create(['name' => 'Kali Puja']);
        EventMember::factory()->owner()->for($this->event)->create(['user_id' => $this->owner->id, 'phone' => $this->owner->phone]);
        EventMember::factory()->for($this->event)->create(['user_id' => $this->member->id, 'phone' => $this->member->phone]);
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
            && $request['amount'] === 100100
            && $request['notes']['event_id'] === $this->event->id);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payments/qr_codes')
            && $request['payment_amount'] === 100100
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
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->owner->id,
            'type' => 'credit',
            'status' => 'success',
            'amount' => 1001,
            'title' => 'Arijit Sen',
            'subtitle' => 'Kali Puja',
            'source_type' => 'contribution',
        ]);
        Notification::assertSentToTimes($this->owner, PaymentReceived::class, 1);

        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'paid');
        $this->getJson("/api/v1/events/{$this->event->id}")
            ->assertJsonPath('data.total_collected', 1001)
            ->assertJsonPath('data.my_contribution', 1001);
    }

    public function test_an_invalid_signature_or_someone_elses_order_is_rejected(): void
    {
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
        $payment = $this->openPayment(501);
        $this->signIn($this->member);

        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'created');

        $this->travel(16)->minutes();
        $this->getJson("/api/v1/payments/{$payment->razorpay_order_id}/status")->assertJsonPath('status', 'expired');
    }

    public function test_a_qr_payment_is_captured_from_the_webhook_once(): void
    {
        $payment = $this->openPayment(701);
        $payload = $this->qrCreditedPayload($payment, 70100);

        $this->webhook($payload, 'evt_1')->assertOk();
        $this->webhook($payload, 'evt_1')->assertOk();
        $this->webhook($payload, 'evt_2')->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(PaymentMethod::UpiQr, $payment->method);
        $this->assertDatabaseCount('contributions', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertNotNull(WebhookEvent::where('event_id', 'evt_1')->value('processed_at'));
    }

    public function test_webhooks_with_a_bad_signature_or_wrong_amount_are_not_applied(): void
    {
        $payment = $this->openPayment(701);

        $this->postJson('/api/v1/webhooks/razorpay', $this->qrCreditedPayload($payment, 70100), [
            'X-Razorpay-Signature' => 'forged',
        ])->assertStatus(400);

        $this->webhook($this->qrCreditedPayload($payment, 100), 'evt_bad')->assertServerError();

        $this->assertSame(PaymentStatus::Created, $payment->refresh()->status);
        $this->assertDatabaseCount('contributions', 0);
        $this->assertStringContainsString('Amount mismatch', WebhookEvent::where('event_id', 'evt_bad')->value('error'));
    }

    private function fakeRazorpay(): void
    {
        Http::fake([
            '*/orders' => Http::response(['id' => 'order_TEST', 'status' => 'created']),
            '*/payments/qr_codes' => Http::response(['id' => 'qr_TEST', 'image_url' => 'https://rzp.io/i/qr']),
        ]);
    }

    private function openPayment(int $amount): Payment
    {
        return Payment::factory()->create([
            'event_id' => $this->event->id,
            'user_id' => $this->member->id,
            'amount' => $amount,
        ]);
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
