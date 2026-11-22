<?php

namespace App\Core\Payments;

use App\Enums\PaymentStatus;
use App\Enums\TransferStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;

/**
 * Sends a captured contribution to the organiser's Route linked account. The platform fee stays
 * behind in the platform's Razorpay account. Failed transfers are retried by `payments:transfer`.
 */
class RouteTransfer
{
    public function __construct(private PaymentGatewayInterface $gateway) {}

    public function transfer(Payment $payment): void
    {
        if (! $this->isDue($payment)) {
            return;
        }

        // The capture callback and the retry command must never transfer the same payment twice.
        Cache::lock("route-transfer:{$payment->id}", 30)->get(function () use ($payment) {
            $payment->refresh();

            if (! $this->isDue($payment)) {
                return;
            }

            try {
                $transferId = $this->gateway->transfer(
                    $payment->razorpay_payment_id,
                    $payment->razorpay_linked_account_id,
                    (float) $payment->amount,
                    $payment->currency,
                    ['event_id' => $payment->event_id, 'payment_id' => $payment->id],
                );

                $payment->update([
                    'razorpay_transfer_id' => $transferId,
                    'transfer_status' => TransferStatus::Transferred,
                    'transfer_error' => null,
                    'transferred_at' => now(),
                ]);
            } catch (PaymentGatewayException $e) {
                report($e);
                $payment->update([
                    'transfer_status' => TransferStatus::Failed,
                    'transfer_error' => str($e->getMessage())->limit(500, '')->toString(),
                ]);
            }
        });
    }

    /**
     * Retries every captured payment whose transfer hasn't gone through. Returns how many were tried.
     */
    public function retryOutstanding(): int
    {
        $count = 0;

        Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereIn('transfer_status', [TransferStatus::Pending, TransferStatus::Failed])
            ->whereNotNull('razorpay_linked_account_id')
            ->orderBy('id')
            ->each(function (Payment $payment) use (&$count) {
                $this->transfer($payment);
                $count++;
            });

        return $count;
    }

    private function isDue(Payment $payment): bool
    {
        return config('payments.route_enabled')
            && $payment->isPaid()
            && $payment->razorpay_payment_id !== null
            && $payment->razorpay_linked_account_id !== null
            && $payment->transfer_status !== TransferStatus::Transferred;
    }
}
