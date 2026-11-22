<?php

namespace App\Http\Resources;

use App\Enums\RouteStatus;
use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The account number is always masked and the PAN never returned.
 *
 * @mixin BankAccount
 */
class PayoutAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'holder_name' => $this->holder_name,
            'account_number' => $this->masked_account_number,
            'ifsc' => $this->ifsc,
            'bank_name' => $this->bank_name,
            'email' => $this->email,
            'address' => [
                'street' => $this->street,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
            ],
            'status' => $this->route_status,
            'status_reason' => $this->route_status_reason,
            'online_payments_enabled' => config('payments.route_enabled') && $this->route_status === RouteStatus::Active,
        ];
    }
}
