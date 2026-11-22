<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Payments\PaymentGatewayInterface;
use App\Core\Payments\PaymentInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/razorpay: confirms QR (`qr_code.credited`) and Checkout (`payment.captured`) payments.
 * Anything but a 2xx makes Razorpay retry, so failures are rethrown after being logged.
 */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGatewayInterface $gateway, PaymentInterface $payments): JsonResponse
    {
        $body = $request->getContent();

        abort_unless(
            $gateway->verifyWebhookSignature($body, (string) $request->header('X-Razorpay-Signature')),
            400,
            'Invalid signature.',
        );

        $payments->handleWebhook(
            $request->header('X-Razorpay-Event-Id') ?: hash('sha256', $body),
            $request->json()->all(),
        );

        return response()->json(['message' => 'OK']);
    }
}
