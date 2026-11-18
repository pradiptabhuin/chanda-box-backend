<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Database\Seeder;

/**
 * The Razorpay webhook that captured each payment, already processed.
 */
class WebhookEventSeeder extends Seeder
{
    public function run(): void
    {
        Payment::whereNotNull('paid_at')->orderBy('id')->each(function (Payment $payment) {
            $type = $payment->method === PaymentMethod::UpiQr ? 'qr_code.credited' : 'payment.captured';

            WebhookEvent::factory()->create([
                'event_type' => $type,
                'payload' => [
                    'event' => $type,
                    'payload' => ['payment' => ['entity' => [
                        'id' => $payment->razorpay_payment_id,
                        'order_id' => $payment->razorpay_order_id,
                        'amount' => (int) round((float) $payment->amount * 100),
                        'currency' => $payment->currency,
                        'status' => 'captured',
                    ]]],
                ],
                'processed_at' => $payment->paid_at,
            ]);
        });
    }
}
