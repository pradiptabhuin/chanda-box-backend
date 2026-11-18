<?php

namespace Database\Seeders;

use App\Models\Contribution;
use App\Models\Payment;
use Illuminate\Database\Seeder;

/**
 * One contribution per captured payment.
 */
class ContributionSeeder extends Seeder
{
    public function run(): void
    {
        Payment::whereNotNull('paid_at')->orderBy('id')->each(fn (Payment $payment) => Contribution::factory()->create([
            'payment_id' => $payment->id,
            'event_id' => $payment->event_id,
            'user_id' => $payment->user_id,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'payment_ref' => $payment->razorpay_payment_id,
            'paid_at' => $payment->paid_at,
        ]));
    }
}
