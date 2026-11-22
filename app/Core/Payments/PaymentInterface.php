<?php

namespace App\Core\Payments;

use App\Enums\PaymentMethod;
use App\Exceptions\PaymentGatewayException;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

interface PaymentInterface
{
    /**
     * Opens a Razorpay order plus a single-use UPI QR for the same amount and stores the attempt.
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(User $payer, Event $event, float $amount): Payment;

    /**
     * Checkout flow: checks the signature the app got from Razorpay, then captures. 404 if the
     * order isn't the payer's.
     *
     * @throws ValidationException when the signature is invalid
     */
    public function verifyCheckout(User $payer, string $orderId, string $paymentId, string $signature): Payment;

    /**
     * The payer's attempt, marked expired once its QR has closed unpaid. 404 if it isn't theirs.
     */
    public function findForPayer(User $payer, string $orderId): Payment;

    /**
     * Marks the payment paid, records the contribution and credits the event owner's wallet, in one
     * transaction. Safe to call more than once for the same payment (checkout + webhook).
     */
    public function capture(Payment $payment, string $gatewayPaymentId, PaymentMethod $method, ?string $signature = null): Payment;

    /**
     * Applies a (signature-checked) Razorpay webhook once per delivery id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(string $deliveryId, array $payload): void;
}
