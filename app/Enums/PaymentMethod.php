<?php

namespace App\Enums;

/**
 * How a contribution was paid.
 */
enum PaymentMethod: string
{
    case UpiQr = 'upi_qr';
    case Razorpay = 'razorpay';
    case Cash = 'cash';
    /** Paid straight to the organiser's UPI ID, outside the platform, and confirmed by the organiser. */
    case Upi = 'upi';

    /**
     * Razorpay checkout or QR (and Route to the organiser). Cash and direct UPI never touch Razorpay:
     * the organiser confirms them by hand.
     */
    public function isViaRazorpay(): bool
    {
        return $this === self::UpiQr || $this === self::Razorpay;
    }

    /**
     * The methods a member reports themselves and the organiser confirms (a cash request).
     *
     * @return list<self>
     */
    public static function organiserConfirmed(): array
    {
        return [self::Cash, self::Upi];
    }

    /**
     * Wording for notifications, e.g. "a cash payment" / "a UPI payment".
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'cash',
            self::Upi, self::UpiQr => 'UPI',
            self::Razorpay => 'online',
        };
    }
}
