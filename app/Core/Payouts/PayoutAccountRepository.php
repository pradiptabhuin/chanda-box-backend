<?php

namespace App\Core\Payouts;

use App\Core\Payments\PaymentGatewayInterface;
use App\Enums\RouteStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PayoutAccountRepository implements PayoutAccountInterface
{
    /**
     * Ask Razorpay about an account under review at most this often.
     */
    private const STATUS_REFRESH_SECONDS = 60;

    public function __construct(private PaymentGatewayInterface $gateway) {}

    public function find(User $user): ?BankAccount
    {
        $account = $user->payoutAccount()->first();

        if ($account !== null && $this->awaitsRazorpay($account)) {
            $this->refreshStatus($account);
        }

        return $account;
    }

    public function save(User $user, array $data): BankAccount
    {
        $data['email'] = ($data['email'] ?? null) ?: $user->email;

        $account = DB::transaction(function () use ($user, $data) {
            $current = $user->payoutAccount()->lockForUpdate()->first();

            // Same Razorpay linked account, new details: keep it and just change the settlement bank.
            if ($current !== null) {
                $current->update($data);

                return $current;
            }

            return $user->payoutAccount()->create([...$data, 'route_status' => RouteStatus::NotLinked]);
        });

        return $this->link($account);
    }

    public function link(BankAccount $account): BankAccount
    {
        if (! config('payments.route_enabled')) {
            $account->update(['route_status' => RouteStatus::NotLinked, 'route_status_reason' => null]);

            return $account;
        }

        try {
            // Each step is saved as it succeeds, so a retry resumes where the last attempt stopped.
            if ($account->razorpay_account_id === null) {
                $account->update(['razorpay_account_id' => $this->gateway->createLinkedAccount($this->linkedAccountDetails($account))]);
            }

            if ($account->razorpay_product_id === null) {
                $product = $this->gateway->requestRoute($account->razorpay_account_id);
                $account->update(['razorpay_product_id' => $product['product_id']]);
            }

            $state = $this->gateway->setSettlementBank($account->razorpay_account_id, $account->razorpay_product_id, [
                'account_number' => $account->account_number,
                'ifsc' => $account->ifsc,
                'holder_name' => $account->holder_name,
            ]);

            $account->update(['route_status' => $state['status'], 'route_status_reason' => $state['reason']]);
        } catch (PaymentGatewayException $e) {
            report($e);
            $account->update([
                'route_status' => RouteStatus::Failed,
                'route_status_reason' => str($e->getMessage())->after('Razorpay: ')->limit(500, '')->toString(),
            ]);
        }

        return $account;
    }

    public function remove(User $user): void
    {
        $user->payoutAccount()->first()?->delete();
    }

    public function acceptsOnlinePayments(User $organiser): bool
    {
        return config('payments.route_enabled')
            && (bool) $organiser->payoutAccount()->where('route_status', RouteStatus::Active)->exists();
    }

    private function awaitsRazorpay(BankAccount $account): bool
    {
        return config('payments.route_enabled')
            && $account->razorpay_product_id !== null
            && in_array($account->route_status, [RouteStatus::Pending, RouteStatus::NeedsClarification], true);
    }

    private function refreshStatus(BankAccount $account): void
    {
        if (! Cache::add("route-status:{$account->id}", true, self::STATUS_REFRESH_SECONDS)) {
            return;
        }

        try {
            $state = $this->gateway->routeStatus($account->razorpay_account_id, $account->razorpay_product_id);
            $account->update(['route_status' => $state['status'], 'route_status_reason' => $state['reason']]);
        } catch (PaymentGatewayException $e) {
            report($e);
        }
    }

    /**
     * @return array{reference_id: string, name: string, email: string, phone: string, pan: string|null, street: string, city: string, state: string, postal_code: string}
     */
    private function linkedAccountDetails(BankAccount $account): array
    {
        return [
            'reference_id' => "user-{$account->user_id}",
            'name' => $account->holder_name,
            'email' => (string) $account->email,
            'phone' => $account->user->phone,
            'pan' => $account->pan,
            ...$account->only(['street', 'city', 'state', 'postal_code']),
        ];
    }
}
