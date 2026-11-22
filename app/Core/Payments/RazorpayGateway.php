<?php

namespace App\Core\Payments;

use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Razorpay Orders + QR Codes REST API (no SDK needed).
 *
 * @see https://razorpay.com/docs/api/orders/create/
 * @see https://razorpay.com/docs/api/payments/qr-codes/create/
 * @see https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/integration-steps/#verify-payment-signature
 */
class RazorpayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private ?string $keyId,
        private ?string $keySecret,
        private ?string $webhookSecret,
        private string $baseUrl = 'https://api.razorpay.com/v1',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret'),
            config('services.razorpay.webhook_secret'),
            config('services.razorpay.base_url'),
        );
    }

    public function publicKey(): string
    {
        return (string) $this->keyId;
    }

    public function createOrder(float $amount, string $currency, string $receipt, array $notes = []): array
    {
        $order = $this->post('orders', [
            'amount' => $this->toPaise($amount),
            'currency' => $currency,
            'receipt' => Str::limit($receipt, 40, ''),
            'notes' => $notes,
        ]);

        return ['id' => $order['id']];
    }

    public function createUpiQr(float $amount, string $label, CarbonInterface $closeBy, array $notes = []): array
    {
        $qr = $this->post('payments/qr_codes', [
            'type' => 'upi_qr',
            'name' => Str::limit($label, 80, ''),
            'usage' => 'single_use',
            'fixed_amount' => true,
            'payment_amount' => $this->toPaise($amount),
            'description' => Str::limit($label, 250, ''),
            'close_by' => $closeBy->getTimestamp(),
            'notes' => $notes,
        ]);

        // Razorpay only returns a rendered image, not the raw UPI string.
        return ['id' => $qr['id'], 'image_url' => $qr['image_url'] ?? null, 'payload' => null];
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        return $this->signatureMatches("{$orderId}|{$paymentId}", $signature, $this->keySecret);
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->signatureMatches($payload, $signature, $this->webhookSecret);
    }

    private function signatureMatches(string $payload, string $signature, ?string $secret): bool
    {
        return filled($secret) && $signature !== ''
            && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private function toPaise(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function post(string $path, array $body): array
    {
        if (blank($this->keyId) || blank($this->keySecret)) {
            throw new PaymentGatewayException('Payments are not configured (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET).');
        }

        try {
            return $this->client()->post($path, $body)->throw()->json();
        } catch (RequestException $e) {
            throw new PaymentGatewayException(
                'Razorpay: '.($e->response->json('error.description') ?? $e->getMessage()),
                previous: $e,
            );
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Razorpay is unreachable: '.$e->getMessage(), previous: $e);
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth((string) $this->keyId, (string) $this->keySecret)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException, throw: false);
    }
}
