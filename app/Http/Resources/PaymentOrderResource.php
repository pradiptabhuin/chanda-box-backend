<?php

namespace App\Http\Resources;

use App\Core\Payments\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment attempt as the app needs it to show the QR or open Checkout.
 *
 * @mixin Payment
 */
class PaymentOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->razorpay_order_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'key_id' => app(PaymentGatewayInterface::class)->publicKey(),
            'qr_payload' => $this->qr_payload,
            'qr_image_url' => $this->qr_image_url,
            'status' => $this->status,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
        ];
    }
}
