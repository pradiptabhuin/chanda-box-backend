<?php

namespace App\Core\Payments;

use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;

/**
 * The payment provider (Razorpay). Amounts are in rupees; implementations convert to the
 * provider's minor unit. Every call that reaches the provider throws PaymentGatewayException on failure.
 */
interface PaymentGatewayInterface
{
    /**
     * Publishable key the app opens Checkout with.
     */
    public function publicKey(): string;

    /**
     * @param  array<string, scalar>  $notes
     * @return array{id: string}
     *
     * @throws PaymentGatewayException
     */
    public function createOrder(float $amount, string $currency, string $receipt, array $notes = []): array;

    /**
     * A single-use, fixed-amount UPI QR that closes at `$closeBy`.
     *
     * @param  array<string, scalar>  $notes
     * @return array{id: string, image_url: string|null, payload: string|null}
     *
     * @throws PaymentGatewayException
     */
    public function createUpiQr(float $amount, string $label, CarbonInterface $closeBy, array $notes = []): array;

    /**
     * Checks the signature Checkout returns to the app after a successful payment.
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Checks a webhook's signature header against its raw body.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
