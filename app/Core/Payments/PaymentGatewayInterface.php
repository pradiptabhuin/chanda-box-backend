<?php

namespace App\Core\Payments;

use App\Enums\RouteStatus;
use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;

/**
 * @phpstan-type GatewayPayment array{id: string, status: string, amount: float, via_qr: bool}
 * @phpstan-type LinkedAccountDetails array{reference_id: string, name: string, email: string, phone: string, pan: string|null, street: string, city: string, state: string, postal_code: string}
 * @phpstan-type SettlementBank array{account_number: string, ifsc: string, holder_name: string}
 * @phpstan-type RouteState array{status: RouteStatus, reason: string|null}
 *
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
     * Payments made against an order (Checkout) or a QR, newest first. Amounts in rupees.
     *
     * @return list<GatewayPayment>
     *
     * @throws PaymentGatewayException
     */
    public function paymentsFor(?string $orderId, ?string $qrId): array;

    /**
     * @return GatewayPayment
     *
     * @throws PaymentGatewayException
     */
    public function fetchPayment(string $paymentId): array;

    /**
     * Settles an authorized payment (needed when the account isn't set to auto-capture).
     *
     * @throws PaymentGatewayException
     */
    public function capturePayment(string $paymentId, float $amount, string $currency): void;

    /**
     * Opens a Route linked account (with its stakeholder) for a payout account owner.
     *
     * @param  LinkedAccountDetails  $details
     *
     * @throws PaymentGatewayException
     */
    public function createLinkedAccount(array $details): string;

    /**
     * Requests the Route product on a linked account.
     *
     * @return array{product_id: string, status: RouteStatus, reason: string|null}
     *
     * @throws PaymentGatewayException
     */
    public function requestRoute(string $accountId): array;

    /**
     * Sets (or changes) the bank a linked account is settled to.
     *
     * @param  SettlementBank  $bank
     * @return RouteState
     *
     * @throws PaymentGatewayException
     */
    public function setSettlementBank(string $accountId, string $productId, array $bank): array;

    /**
     * @return RouteState
     *
     * @throws PaymentGatewayException
     */
    public function routeStatus(string $accountId, string $productId): array;

    /**
     * Moves part of a captured payment to a linked account; returns the transfer id.
     *
     * @param  array<string, scalar>  $notes
     *
     * @throws PaymentGatewayException
     */
    public function transfer(string $paymentId, string $accountId, float $amount, string $currency, array $notes = []): string;

    /**
     * Checks the signature Checkout returns to the app after a successful payment.
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Checks a webhook's signature header against its raw body.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
