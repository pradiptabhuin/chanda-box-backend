<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Database\Seeders\Concerns\FindsSeedRecords;
use Illuminate\Database\Seeder;

/**
 * 10 captured payments, each by the event's owner or a joined member.
 */
class PaymentSeeder extends Seeder
{
    use FindsSeedRecords;

    /**
     * @var list<array{event: string, phone: string, amount: int, method: PaymentMethod, days_ago: int}>
     */
    public const PAYMENTS = [
        ['event' => 'Sarbojanin Durga Puja', 'phone' => UserSeeder::DEMO_PHONE, 'amount' => 5001, 'method' => PaymentMethod::UpiQr, 'days_ago' => 9],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000002', 'amount' => 1001, 'method' => PaymentMethod::UpiQr, 'days_ago' => 8],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000003', 'amount' => 2001, 'method' => PaymentMethod::Razorpay, 'days_ago' => 7],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000004', 'amount' => 1001, 'method' => PaymentMethod::UpiQr, 'days_ago' => 6],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000002', 'amount' => 501, 'method' => PaymentMethod::UpiQr, 'days_ago' => 3],
        ['event' => 'Sarbojanin Durga Puja', 'phone' => '9800000003', 'amount' => 1001, 'method' => PaymentMethod::Razorpay, 'days_ago' => 2],
        ['event' => 'Saraswati Puja', 'phone' => '9800000005', 'amount' => 501, 'method' => PaymentMethod::UpiQr, 'days_ago' => 5],
        ['event' => 'Kali Puja', 'phone' => UserSeeder::DEMO_PHONE, 'amount' => 701, 'method' => PaymentMethod::UpiQr, 'days_ago' => 4],
        ['event' => 'Kali Puja', 'phone' => UserSeeder::DEMO_PHONE, 'amount' => 501, 'method' => PaymentMethod::Razorpay, 'days_ago' => 1],
        ['event' => 'Jagaddhatri Puja', 'phone' => UserSeeder::DEMO_PHONE, 'amount' => 1001, 'method' => PaymentMethod::UpiQr, 'days_ago' => 2],
    ];

    public function run(): void
    {
        foreach (self::PAYMENTS as $payment) {
            $paidAt = now()->subDays($payment['days_ago'])->setTime(11, 15);

            Payment::factory()->paid($payment['method'])->create([
                'event_id' => $this->event($payment['event'])->id,
                'user_id' => $this->user($payment['phone'])->id,
                'amount' => $payment['amount'],
                'qr_payload' => "upi://pay?pa=chandabox@razorpay&am={$payment['amount']}.00&cu=INR",
                'expires_at' => $paidAt->copy()->addMinutes(15),
                'paid_at' => $paidAt,
                'created_at' => $paidAt->copy()->subMinutes(2),
                'updated_at' => $paidAt,
            ]);
        }
    }
}
