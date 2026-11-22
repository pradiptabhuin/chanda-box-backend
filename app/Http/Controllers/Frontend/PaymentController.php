<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Payments\PaymentInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentOrderRequest;
use App\Http\Requests\Payment\VerifyPaymentRequest;
use App\Http\Resources\PaymentOrderResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentInterface $paymentInterface) {}

    /**
     * POST /events/{event}/payments/order
     */
    public function store(CreatePaymentOrderRequest $request, Event $event): JsonResponse
    {
        $payment = $this->paymentInterface->createOrder($request->user(), $event, $request->amount());

        return (new PaymentOrderResource($payment))->response()->setStatusCode(201);
    }

    /**
     * POST /payments/verify (Razorpay Checkout success callback).
     */
    public function verify(VerifyPaymentRequest $request): JsonResponse
    {
        $payment = $this->paymentInterface->verifyCheckout(
            $request->user(),
            $request->validated('razorpay_order_id'),
            $request->validated('razorpay_payment_id'),
            $request->validated('razorpay_signature'),
        );

        return response()->json(['status' => $payment->status]);
    }

    /**
     * GET /payments/{orderId}/status, polled by the app while it shows the QR.
     */
    public function status(Request $request, string $orderId): JsonResponse
    {
        return response()->json([
            'status' => $this->paymentInterface->findForPayer($request->user(), $orderId)->status,
        ]);
    }
}
