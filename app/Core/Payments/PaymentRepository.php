<?php

namespace App\Core\Payments;

use App\Core\Contributions\ContributionRecorder;
use App\Core\Payouts\PayoutAccountInterface;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransferStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PaymentRepository implements PaymentInterface
{
    private const CURRENCY = 'INR';

    /**
     * Razorpay statuses that mean the money was taken (authorized still needs a capture call).
     */
    private const SUCCESSFUL = ['captured', 'authorized'];

    /**
     * The app polls every 5 s; ask Razorpay at most this often per payment.
     */
    private const SYNC_INTERVAL_SECONDS = 4;

    public function __construct(
        private PaymentGatewayInterface $gateway,
        private ContributionRecorder $recorder,
        private FeeCalculator $fees,
        private PayoutAccountInterface $payoutAccounts,
        private RouteTransfer $transfers,
    ) {}

    public function createOrder(User $payer, Event $event, float $amount, ?User $member = null): Payment
    {
        // Online money must go straight to the organiser's bank, so no Route account means no online payment.
        if (! $this->payoutAccounts->acceptsOnlinePayments($event->owner)) {
            throw ValidationException::withMessages(['amount' => "The organiser hasn't set up online payments yet."]);
        }

        $charged = $this->fees->chargedAmount($amount);
        $expiresAt = now()->addMinutes(config('services.razorpay.qr_ttl_minutes'));
        $notes = array_filter([
            'event_id' => $event->id,
            'user_id' => $payer->id,
            'on_behalf_of' => $member?->id,
        ]);

        $order = $this->gateway->createOrder($charged, self::CURRENCY, "e{$event->id}-u{$payer->id}-".now()->timestamp, $notes);

        // The QR is a convenience: Checkout (UPI apps, cards, netbanking) still works without it,
        // e.g. on accounts where Razorpay hasn't enabled QR codes.
        try {
            $qr = $this->gateway->createUpiQr($charged, $event->name, $expiresAt, [...$notes, 'order_id' => $order['id']]);
        } catch (PaymentGatewayException $e) {
            report($e);
            $qr = ['id' => null, 'image_url' => null, 'payload' => null];
        }

        return $payer->payments()->create([
            'event_id' => $event->id,
            'on_behalf_of' => $member?->id,
            'amount' => $amount,
            'platform_fee' => $this->fees->platformFee(),
            'charged_amount' => $charged,
            'razorpay_linked_account_id' => $event->owner->payoutAccount->razorpay_account_id,
            'transfer_status' => TransferStatus::Pending,
            'currency' => self::CURRENCY,
            'razorpay_order_id' => $order['id'],
            'razorpay_qr_id' => $qr['id'],
            'qr_payload' => $qr['payload'],
            'qr_image_url' => $qr['image_url'],
            'status' => PaymentStatus::Created,
            'expires_at' => $expiresAt,
        ]);
    }

    public function verifyCheckout(User $payer, string $orderId, string $paymentId, string $signature): Payment
    {
        $payment = $this->payerPayment($payer, $orderId);

        if (! $this->gateway->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            throw ValidationException::withMessages(['razorpay_signature' => 'Payment verification failed.']);
        }

        // The signature proves Razorpay took the money; settling it is best effort (webhooks retry).
        try {
            $this->settle($this->gateway->fetchPayment($paymentId), $payment);
        } catch (PaymentGatewayException $e) {
            report($e);
        }

        return $this->capture($payment, $paymentId, PaymentMethod::Razorpay, $signature);
    }

    public function findForPayer(User $payer, string $orderId): Payment
    {
        $payment = $this->payerPayment($payer, $orderId);

        if ($payment->status === PaymentStatus::Paid) {
            return $payment;
        }

        $this->syncWithGateway($payment);

        if ($payment->status === PaymentStatus::Created && $payment->expires_at->isPast()) {
            $payment->update(['status' => PaymentStatus::Expired]);
        }

        return $payment;
    }

    public function capture(Payment $payment, string $gatewayPaymentId, PaymentMethod $method, ?string $signature = null): Payment
    {
        DB::transaction(function () use ($payment, $gatewayPaymentId, $method, $signature): void {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                return;
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'method' => $method,
                'razorpay_payment_id' => $gatewayPaymentId,
                'razorpay_signature' => $signature,
                'paid_at' => now(),
                'failure_reason' => null,
            ]);

            $this->recorder->record(
                event: $locked->event()->withTrashed()->firstOrFail(),
                member: User::findOrFail($locked->contributorId()),
                payer: $locked->user,
                amount: $locked->amount,
                method: $method,
                reference: $gatewayPaymentId,
                source: $locked,
            );

            DB::afterCommit(fn () => $this->transfers->transfer($locked));
        });

        return $payment->refresh();
    }

    public function handleWebhook(string $deliveryId, array $payload): void
    {
        $type = (string) ($payload['event'] ?? '');

        $log = WebhookEvent::firstOrCreate(
            ['event_id' => $deliveryId],
            ['provider' => 'razorpay', 'event_type' => $type, 'payload' => $payload],
        );

        if ($log->processed_at !== null) {
            return;
        }

        try {
            match ($type) {
                'payment.captured' => $this->captureFromWebhook(
                    Payment::where('razorpay_order_id', Arr::get($payload, 'payload.payment.entity.order_id'))->first(),
                    $payload,
                    PaymentMethod::Razorpay,
                ),
                'qr_code.credited' => $this->captureFromWebhook(
                    Payment::where('razorpay_qr_id', Arr::get($payload, 'payload.qr_code.entity.id'))->first(),
                    $payload,
                    PaymentMethod::UpiQr,
                ),
                default => null,
            };

            $log->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $log->update(['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Asks Razorpay whether the order or QR has been paid, for setups where the webhook can't reach
     * us (local development, or no webhook configured yet). Failures just leave the status as is.
     */
    private function syncWithGateway(Payment $payment): void
    {
        if (! Cache::add("payment-sync:{$payment->id}", true, self::SYNC_INTERVAL_SECONDS)) {
            return;
        }

        try {
            $paid = collect($this->gateway->paymentsFor($payment->razorpay_order_id, $payment->razorpay_qr_id))
                ->first(fn (array $gatewayPayment) => in_array($gatewayPayment['status'], self::SUCCESSFUL, true)
                    && $this->covers($gatewayPayment['amount'], $payment));

            if ($paid === null) {
                return;
            }

            $this->settle($paid, $payment);
            $this->capture($payment, $paid['id'], $paid['via_qr'] ? PaymentMethod::UpiQr : PaymentMethod::Razorpay);
        } catch (PaymentGatewayException $e) {
            report($e);
        }
    }

    /**
     * Captures an authorized payment, for accounts not set to auto-capture.
     *
     * @param  array{id: string, status: string, amount: float, via_qr: bool}  $gatewayPayment
     *
     * @throws PaymentGatewayException
     */
    private function settle(array $gatewayPayment, Payment $payment): void
    {
        // Capture what was actually authorized (it includes Razorpay's fee under Customer Fee Bearer).
        if ($gatewayPayment['status'] === 'authorized') {
            $this->gateway->capturePayment($gatewayPayment['id'], $gatewayPayment['amount'], $payment->currency);
        }
    }

    /**
     * Webhooks for orders we didn't create (e.g. other integrations on the account) are ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    private function captureFromWebhook(?Payment $payment, array $payload, PaymentMethod $method): void
    {
        $entity = Arr::get($payload, 'payload.payment.entity');

        if ($payment === null || ! is_array($entity) || blank($entity['id'] ?? null)) {
            return;
        }

        if (! $this->covers(((int) ($entity['amount'] ?? 0)) / 100, $payment)) {
            throw new RuntimeException("Amount mismatch for payment {$payment->id}.");
        }

        $this->capture($payment, $entity['id'], $method);
    }

    /**
     * The payer may pay more than the order (Razorpay's card fee under Customer Fee Bearer), never less.
     */
    private function covers(float $rupees, Payment $payment): bool
    {
        return (int) round($rupees * 100) >= (int) round($payment->chargedAmount() * 100);
    }

    private function payerPayment(User $payer, string $orderId): Payment
    {
        return $payer->payments()->where('razorpay_order_id', $orderId)->firstOrFail();
    }
}
