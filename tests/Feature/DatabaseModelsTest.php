<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\EventSchedule;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema, casts and relationships of the ChandaBox tables.
 */
class DatabaseModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_name_is_split_into_parts_and_joined_back(): void
    {
        $user = User::factory()->create(['name' => 'Arijit Kumar Sen']);

        $this->assertSame('Arijit', $user->first_name);
        $this->assertSame('Kumar', $user->middle_name);
        $this->assertSame('Sen', $user->last_name);
        $this->assertSame('Arijit Kumar Sen', $user->fresh()->name);

        $single = User::factory()->create(['name' => 'Arijit']);
        $this->assertNull($single->last_name);
        $this->assertSame('Arijit', $single->fresh()->name);
    }

    public function test_event_has_owner_schedule_and_members(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner, 'owner')->create();
        EventMember::factory()->owner()->for($event)->for($owner)->create();
        EventMember::factory()->for($event)->create();
        EventMember::factory()->invited()->for($event)->create(['invited_by' => $owner->id]);
        EventMember::factory()->unregistered()->for($event)->create();
        EventSchedule::factory()->for($event)->create(['sort_order' => 2, 'title' => 'Second']);
        EventSchedule::factory()->for($event)->create(['sort_order' => 1, 'title' => 'First']);

        $this->assertTrue($event->owner->is($owner));
        $this->assertSame(['First', 'Second'], $event->schedules->pluck('title')->all());
        $this->assertCount(4, $event->members);
        $this->assertCount(2, $event->joinedMembers);
        $this->assertCount(2, $event->pendingInvitations);
        $this->assertSame(MemberRole::Owner, $event->members->firstWhere('user_id', $owner->id)->role);
        $this->assertTrue($owner->ownedEvents->first()->is($event));
        $this->assertTrue($owner->joinedEvents->first()->is($event));
    }

    public function test_an_invitation_appears_in_the_invitees_pending_list(): void
    {
        $invitee = User::factory()->create();
        $member = EventMember::factory()->invited()->for($invitee)->create();

        $this->assertSame(MemberStatus::Invited, $member->status);
        $this->assertTrue($invitee->pendingInvitations->first()->is($member));
        $this->assertCount(0, $invitee->joinedEvents);
    }

    public function test_the_same_phone_cannot_be_invited_twice_to_an_event(): void
    {
        $member = EventMember::factory()->invited()->create();

        $this->expectException(QueryException::class);

        EventMember::factory()->invited()->for($member->event)->create(['phone' => $member->phone, 'user_id' => null]);
    }

    public function test_contribution_links_payment_and_credits_the_owner_wallet(): void
    {
        $contribution = Contribution::factory()->create();
        $credit = WalletTransaction::factory()->forContribution($contribution)->create();

        $this->assertTrue($contribution->payment->isPaid());
        $this->assertSame(PaymentMethod::UpiQr, $contribution->method);
        $this->assertTrue($contribution->payment->contribution->is($contribution));
        $this->assertTrue($credit->source->is($contribution));
        $this->assertTrue($contribution->walletTransaction->is($credit));
        $this->assertSame($contribution->event->owner_id, $credit->user_id);
        $this->assertSame('contribution', DB::table('wallet_transactions')->value('source_type'));
    }

    public function test_one_payment_can_only_produce_one_contribution(): void
    {
        $contribution = Contribution::factory()->create();

        $this->expectException(QueryException::class);

        Contribution::factory()->create(['payment_id' => $contribution->payment_id]);
    }

    public function test_bank_account_number_is_encrypted_and_masked(): void
    {
        $account = BankAccount::factory()->create(['account_number' => '123456784321']);

        $stored = DB::table('bank_accounts')->where('id', $account->id)->first();
        $this->assertNotSame('123456784321', $stored->account_number);
        $this->assertSame('4321', $stored->account_last4);
        $this->assertSame('123456784321', $account->fresh()->account_number);
        $this->assertSame('XXXXXXXX4321', $account->masked_account_number);
        $this->assertArrayNotHasKey('account_number', $account->toArray());
    }

    public function test_withdrawal_keeps_its_soft_deleted_bank_account_and_debit(): void
    {
        $withdrawal = Withdrawal::factory()->create();
        $debit = WalletTransaction::factory()->debit()->create([
            'user_id' => $withdrawal->user_id,
            'source_id' => $withdrawal->id,
        ]);
        $withdrawal->bankAccount->delete();

        $this->assertNotNull($withdrawal->fresh()->bankAccount);
        $this->assertSame(TransactionType::Debit, $debit->type);
        $this->assertSame(TransactionStatus::Pending, $debit->status);
        $this->assertTrue($withdrawal->walletTransaction->is($debit));
    }

    public function test_users_receive_database_notifications(): void
    {
        $user = User::factory()->create();

        DB::table('notifications')->insert([
            'id' => fake()->uuid(),
            'type' => 'invitation',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'New invitation', 'body' => 'You were invited']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('New invitation', $user->unreadNotifications->first()->data['title']);
    }
}
