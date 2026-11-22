<?php

namespace App\Core\Payments;

use App\Enums\RouteStatus;
use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Razorpay Orders + QR Codes REST API (no SDK needed).
 *
 * @see https://razorpay.com/docs/api/orders/create/
 * @see https://razorpay.com/docs/api/payments/qr-codes/create/
 * @see https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/integration-steps/#verify-payment-signature
 * @see https://razorpay.com/docs/api/payments/route/create-linked-account/
 * @see https://razorpay.com/docs/api/payments/route/transfers/
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

    public function paymentsFor(?string $orderId, ?string $qrId): array
    {
        $payments = [];

        if (filled($orderId)) {
            foreach ($this->get("orders/{$orderId}/payments")['items'] ?? [] as $payment) {
                $payments[] = $this->toPayment($payment);
            }
        }

        if (filled($qrId)) {
            foreach ($this->get("payments/qr_codes/{$qrId}/payments")['items'] ?? [] as $payment) {
                $payments[] = $this->toPayment($payment, viaQr: true);
            }
        }

        return $payments;
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->toPayment($this->get("payments/{$paymentId}"));
    }

    public function capturePayment(string $paymentId, float $amount, string $currency): void
    {
        $this->post("payments/{$paymentId}/capture", ['amount' => $this->toPaise($amount), 'currency' => $currency]);
    }

    public function createLinkedAccount(array $details): string
    {
        $profile = config('payments.linked_account');

        $account = $this->post($this->v2('accounts'), array_filter([
            'email' => $details['email'],
            'phone' => $details['phone'],
            'type' => 'route',
            'reference_id' => $details['reference_id'],
            'legal_business_name' => $details['name'],
            'business_type' => $profile['business_type'],
            'contact_name' => $details['name'],
            'profile' => [
                'category' => $profile['category'],
                'subcategory' => $profile['subcategory'],
                'addresses' => [
                    'registered' => [
                        'street1' => Str::limit($details['street'], 100, ''),
                        'street2' => Str::limit($details['city'], 100, ''),
                        'city' => $details['city'],
                        'state' => $details['state'],
                        'postal_code' => $details['postal_code'],
                        'country' => 'IN',
                    ],
                ],
            ],
            'legal_info' => filled($details['pan']) ? ['pan' => $details['pan']] : null,
        ]));

        $this->post($this->v2("accounts/{$account['id']}/stakeholders"), array_filter([
            'name' => $details['name'],
            'email' => $details['email'],
            'kyc' => filled($details['pan']) ? ['pan' => $details['pan']] : null,
        ]));

        return $account['id'];
    }

    public function requestRoute(string $accountId): array
    {
        $product = $this->post($this->v2("accounts/{$accountId}/products"), [
            'product_name' => 'route',
            'tnc_accepted' => true,
        ]);

        return ['product_id' => $product['id'], ...$this->toRouteState($product)];
    }

    public function setSettlementBank(string $accountId, string $productId, array $bank): array
    {
        return $this->toRouteState($this->send(fn (PendingRequest $client) => $client->patch(
            $this->v2("accounts/{$accountId}/products/{$productId}"),
            [
                'settlements' => [
                    'account_number' => $bank['account_number'],
                    'ifsc_code' => $bank['ifsc'],
                    'beneficiary_name' => $bank['holder_name'],
                ],
                'tnc_accepted' => true,
            ],
        )));
    }

    public function routeStatus(string $accountId, string $productId): array
    {
        return $this->toRouteState($this->get($this->v2("accounts/{$accountId}/products/{$productId}")));
    }

    public function transfer(string $paymentId, string $accountId, float $amount, string $currency, array $notes = []): string
    {
        $response = $this->post("payments/{$paymentId}/transfers", [
            'transfers' => [[
                'account' => $accountId,
                'amount' => $this->toPaise($amount),
                'currency' => $currency,
                'notes' => $notes,
                'on_hold' => false,
            ]],
        ]);

        return (string) ($response['items'][0]['id'] ?? throw new PaymentGatewayException('Razorpay: transfer response had no id.'));
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

    /**
     * The Accounts API lives under /v2.
     */
    private function v2(string $path): string
    {
        return preg_replace('#/v1/?$#', '/v2', rtrim($this->baseUrl, '/')).'/'.$path;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{status: RouteStatus, reason: string|null}
     */
    private function toRouteState(array $product): array
    {
        $status = match ($product['activation_status'] ?? null) {
            'activated' => RouteStatus::Active,
            'needs_clarification' => RouteStatus::NeedsClarification,
            'suspended' => RouteStatus::Failed,
            default => RouteStatus::Pending,
        };

        $reasons = collect($product['requirements'] ?? [])
            ->map(fn (array $requirement) => $requirement['description'] ?? $requirement['reason_code'] ?? null)
            ->filter()
            ->unique()
            ->implode(' ');

        return ['status' => $status, 'reason' => $reasons !== '' ? Str::limit($reasons, 500, '') : null];
    }

    private function toPaise(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array{id: string, status: string, amount: float, via_qr: bool}
     */
    private function toPayment(array $payment, bool $viaQr = false): array
    {
        return [
            'id' => (string) $payment['id'],
            'status' => (string) ($payment['status'] ?? ''),
            'amount' => ((int) ($payment['amount'] ?? 0)) / 100,
            'via_qr' => $viaQr,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function get(string $path): array
    {
        return $this->send(fn (PendingRequest $client) => $client->get($path));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function post(string $path, array $body): array
    {
        return $this->send(fn (PendingRequest $client) => $client->post($path, $body));
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException
     */
    private function send(callable $request): array
    {
        if (blank($this->keyId) || blank($this->keySecret)) {
            throw new PaymentGatewayException('Payments are not configured (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET).');
        }

        try {
            return $request($this->client())->throw()->json() ?? [];
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
