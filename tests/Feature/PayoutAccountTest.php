<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The payout account (bank) Razorpay Route pays an organiser's online contributions into.
 */
class PayoutAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'secret',
            'payments.route_enabled' => true,
        ]);
        Http::preventStrayRequests();

        $this->me = User::factory()->create(['first_name' => 'Ananya', 'last_name' => 'Roy', 'email' => 'ananya@example.com']);
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);
    }

    public function test_saving_registers_a_route_linked_account_settled_to_the_bank(): void
    {
        $this->fakeRazorpay();

        $this->putJson('/api/v1/payout-account', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.account_number', 'XXXXXXXX4321')
            ->assertJsonPath('data.ifsc', 'SBIN0001234')
            ->assertJsonPath('data.email', 'ananya@example.com')
            ->assertJsonPath('data.address.postal_code', '700091')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.online_payments_enabled', false)
            ->assertJsonMissingPath('data.pan');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v2/accounts'
            && $request['type'] === 'route'
            && $request['phone'] === $this->me->phone
            && $request['legal_info']['pan'] === 'ABCDE1234F'
            && $request['profile']['addresses']['registered']['postal_code'] === '700091');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/accounts/acc_NEW/stakeholders'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/accounts/acc_NEW/products')
            && $request['product_name'] === 'route');
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request['settlements'] === ['account_number' => '123456784321', 'ifsc_code' => 'SBIN0001234', 'beneficiary_name' => 'Ananya Roy']);

        $account = $this->me->payoutAccount;
        $this->assertSame('acc_NEW', $account->razorpay_account_id);
        $this->assertSame('acc_prd_NEW', $account->razorpay_product_id);
        $this->assertSame('ABCDE1234F', $account->pan);
    }

    public function test_the_status_is_refreshed_from_razorpay_while_under_review(): void
    {
        $this->fakeRazorpay(['*/v2/accounts/*/products/*' => Http::sequence()
            ->push(['activation_status' => 'under_review'])   // PATCH settlement
            ->push(['activation_status' => 'activated']),      // GET status
        ]);
        $this->putJson('/api/v1/payout-account', $this->payload())->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/v1/payout-account')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.online_payments_enabled', true);
    }

    public function test_razorpay_asking_for_more_details_is_shown_with_its_reason(): void
    {
        $this->fakeRazorpay(['*/v2/accounts/*/products/*' => Http::response([
            'activation_status' => 'needs_clarification',
            'requirements' => [['field_reference' => 'settlements.ifsc_code', 'reason_code' => 'field_missing', 'description' => 'IFSC could not be verified.']],
        ])]);

        $this->putJson('/api/v1/payout-account', $this->payload())
            ->assertJsonPath('data.status', 'needs_clarification')
            ->assertJsonPath('data.status_reason', 'IFSC could not be verified.');
    }

    public function test_a_razorpay_error_is_saved_and_the_link_can_be_retried_from_where_it_stopped(): void
    {
        $this->fakeRazorpay(['*/v2/accounts/acc_NEW/products' => Http::sequence()
            ->push(['error' => ['description' => 'Route is not enabled for this account']], 400)
            ->push(['id' => 'acc_prd_NEW', 'activation_status' => 'requested']),
        ]);

        $this->putJson('/api/v1/payout-account', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.status_reason', 'Route is not enabled for this account');

        $this->postJson('/api/v1/payout-account/retry')->assertJsonPath('data.status', 'pending');

        // The linked account was created once; the retry resumed at the product step.
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v2/accounts'));
    }

    public function test_with_route_off_the_account_is_saved_without_calling_razorpay_and_linked_later(): void
    {
        config(['payments.route_enabled' => false]);

        $this->putJson('/api/v1/payout-account', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'not_linked')
            ->assertJsonPath('data.online_payments_enabled', false);
        Http::assertNothingSent();

        config(['payments.route_enabled' => true]);
        $this->fakeRazorpay();
        $this->artisan('payouts:link')->expectsOutput('Ananya Roy: pending')->assertSuccessful();
    }

    public function test_changing_the_bank_keeps_the_linked_account_and_updates_settlement(): void
    {
        $this->fakeRazorpay();
        $this->putJson('/api/v1/payout-account', $this->payload())->assertOk();

        $this->putJson('/api/v1/payout-account', [...$this->payload(), 'account_number' => '999988887777', 'bank_name' => 'HDFC Bank'])
            ->assertJsonPath('data.account_number', 'XXXXXXXX7777')
            ->assertJsonPath('data.bank_name', 'HDFC Bank');

        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->url() === 'https://api.razorpay.com/v2/accounts'));
        $this->assertCount(2, Http::recorded(fn (Request $request) => $request->method() === 'PATCH'));
        $this->assertSame(1, BankAccount::count());
    }

    public function test_details_are_validated_and_email_is_required_without_a_profile_email(): void
    {
        $this->putJson('/api/v1/payout-account', [...$this->payload(), 'ifsc' => 'SBIN1234', 'pan' => '12345', 'postal_code' => '7000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ifsc', 'pan', 'postal_code'])
            ->assertJsonMissingValidationErrors('email');

        $this->me->update(['email' => null]);
        $this->putJson('/api/v1/payout-account', $this->payload())->assertJsonValidationErrors('email');
    }

    public function test_the_account_can_be_viewed_and_removed(): void
    {
        $this->getJson('/api/v1/payout-account')->assertExactJson(['data' => null]);
        $this->postJson('/api/v1/payout-account/retry')->assertNotFound();

        BankAccount::factory()->for($this->me)->active()->create();
        $this->getJson('/api/v1/payout-account')->assertJsonPath('data.status', 'active');

        $this->deleteJson('/api/v1/payout-account')->assertOk();
        $this->getJson('/api/v1/payout-account')->assertExactJson(['data' => null]);
        $this->assertSoftDeleted('bank_accounts');
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'holder_name' => 'Ananya Roy',
            'account_number' => '1234 5678 4321',
            'ifsc' => 'sbin0001234',
            'bank_name' => 'State Bank of India',
            'pan' => 'abcde1234f',
            'street' => '12 Netaji Park',
            'city' => 'Kolkata',
            'state' => 'West Bengal',
            'postal_code' => '700091',
        ];
    }

    /**
     * Razorpay's linked-account endpoints; `$overrides` are matched first.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeRazorpay(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/v2/accounts/*/stakeholders' => Http::response(['id' => 'sth_NEW']),
            '*/v2/accounts/*/products/*' => Http::response(['id' => 'acc_prd_NEW', 'activation_status' => 'under_review']),
            '*/v2/accounts/*/products' => Http::response(['id' => 'acc_prd_NEW', 'activation_status' => 'requested']),
            '*/v2/accounts' => Http::response(['id' => 'acc_NEW', 'status' => 'created']),
        ]);
    }
}
