<?php

namespace App\Core\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\PaymentReceived;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PaymentRepository implements PaymentInterface
{
    private const CURRENCY = 'INR';

    public function __construct(private PaymentGatewayInterface $gateway) {}

    public function createOrder(User $payer, Event $event, float $amount): Payment
    {
        $expiresAt = now()->addMinutes(config('services.razorpay.qr_ttl_minutes'));
        $notes = ['event_id' => $event->id, 'user_id' => $payer->id];

        $order = $this->gateway->createOrder($amount, self::CURRENCY, "e{$event->id}-u{$payer->id}-".now()->timestamp, $notes);
        $qr = $this->gateway->createUpiQr($amount, $event->name, $expiresAt, [...$notes, 'order_id' => $order['id']]);

        return $payer->payments()->create([
            'event_id' => $event->id,
            'amount' => $amount,
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

        return $this->capture($payment, $paymentId, PaymentMethod::Razorpay, $signature);
    }

    public function findForPayer(User $payer, string $orderId): Payment
    {
        $payment = $this->payerPayment($payer, $orderId);

        if ($payment->status === PaymentStatus::Created && $payment->expires_at->isPast()) {
            $payment->update(['status' => PaymentStatus::Expired]);
        }

        return $payment;
    }

    public function capture(Payment $payment, string $gatewayPaymentId, PaymentMethod $method, ?string $signature = null): Payment
    {
        $contribution = DB::transaction(function () use ($payment, $gatewayPaymentId, $method, $signature): ?Contribution {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                return null;
            }

            $paidAt = now();
            $locked->update([
                'status' => PaymentStatus::Paid,
                'method' => $method,
                'razorpay_payment_id' => $gatewayPaymentId,
                'razorpay_signature' => $signature,
                'paid_at' => $paidAt,
                'failure_reason' => null,
            ]);

            /** @var Event $event */
            $event = $locked->event()->withTrashed()->firstOrFail();

            /** @var Contribution $contribution */
            $contribution = $locked->contribution()->create([
                'event_id' => $locked->event_id,
                'user_id' => $locked->user_id,
                'amount' => $locked->amount,
                'method' => $method,
                'payment_ref' => $gatewayPaymentId,
                'paid_at' => $paidAt,
            ]);

            $contribution->setRelation('event', $event)->setRelation('user', $locked->user);

            // The money is the event owner's to withdraw.
            $contribution->walletTransaction()->create([
                'user_id' => $event->owner_id,
                'type' => TransactionType::Credit,
                'status' => TransactionStatus::Success,
                'amount' => $locked->amount,
                'title' => $contribution->user->name,
                'subtitle' => $event->name,
            ]);

            return $contribution;
        });

        if ($contribution !== null && $contribution->event->owner_id !== $contribution->user_id) {
            $contribution->event->owner->notify(new PaymentReceived($contribution));
        }

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

        if ((int) ($entity['amount'] ?? 0) !== (int) round((float) $payment->amount * 100)) {
            throw new RuntimeException("Amount mismatch for payment {$payment->id}.");
        }

        $this->capture($payment, $entity['id'], $method);
    }

    private function payerPayment(User $payer, string $orderId): Payment
    {
        return $payer->payments()->where('razorpay_order_id', $orderId)->firstOrFail();
    }
}
