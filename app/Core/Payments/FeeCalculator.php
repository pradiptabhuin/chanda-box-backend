<?php

namespace App\Core\Payments;

/**
 * What an online contribution costs the payer. The organiser always receives the contribution in full.
 *
 * - Platform fee: flat, kept in the platform's Razorpay account.
 * - UPI: no payment charges (UPI / RuPay can't carry any charge under PSS Act s.10A).
 * - Cards / netbanking: Razorpay adds its fee at checkout when Customer Fee Bearer is enabled;
 *   the amount here is only an estimate for the payer. Otherwise the platform absorbs it.
 */
class FeeCalculator
{
    public function platformFee(): float
    {
        return round((float) config('payments.platform_fee'), 2);
    }

    /**
     * Amount the order is created for (UPI pays exactly this).
     */
    public function chargedAmount(float $contribution): float
    {
        return round($contribution + $this->platformFee(), 2);
    }

    /**
     * @return array{contribution: float, platform_fee: float, upi_charges: float, upi_total: float, card_charges: float, card_total: float, charges_collected_by_razorpay: bool}
     */
    public function quote(float $contribution): array
    {
        $charged = $this->chargedAmount($contribution);
        $collectedByRazorpay = (bool) config('payments.customer_fee_bearer');
        $cardCharges = $collectedByRazorpay
            ? round($charged * config('payments.card_fee_percent') / 100 * (1 + config('payments.gst_percent') / 100), 2)
            : 0.0;

        return [
            'contribution' => round($contribution, 2),
            'platform_fee' => $this->platformFee(),
            'upi_charges' => 0.0,
            'upi_total' => $charged,
            'card_charges' => $cardCharges,
            'card_total' => round($charged + $cardCharges, 2),
            'charges_collected_by_razorpay' => $collectedByRazorpay,
        ];
    }
}
