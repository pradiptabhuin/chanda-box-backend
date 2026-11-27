<?php

namespace App\Core\Contributions;

use App\Enums\PaymentMethod;
use App\Models\CashRequest;
use App\Models\Contribution;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaidOnYourBehalf;
use App\Notifications\PaymentReceived;
use Illuminate\Support\Facades\DB;

/**
 * The one place a contribution is written, for online payments and cash alike.
 * Call it inside the caller's transaction: notifications go out only after commit.
 */
class ContributionRecorder
{
    /**
     * @param  User  $member  who the money counts for
     * @param  User  $payer  who actually paid (the member, or the owner on their behalf)
     */
    public function record(
        Event $event,
        User $member,
        User $payer,
        float|string $amount,
        PaymentMethod $method,
        string $reference,
        Payment|CashRequest $source,
        ?string $note = null,
    ): Contribution {
        /** @var Contribution $contribution */
        $contribution = $event->contributions()->create([
            'user_id' => $member->id,
            'paid_by' => $payer->is($member) ? null : $payer->id,
            'payment_id' => $source instanceof Payment ? $source->id : null,
            'cash_request_id' => $source instanceof CashRequest ? $source->id : null,
            'amount' => $amount,
            'method' => $method,
            'payment_ref' => $reference,
            'note' => $note,
            'paid_at' => now(),
        ]);

        $contribution->setRelation('event', $event)->setRelation('user', $member)->setRelation('payer', $payer);

        DB::afterCommit(fn () => $this->notify($contribution, $payer));

        return $contribution;
    }

    /**
     * Owner: told about Razorpay money someone else paid (cash and direct UPI they confirm themselves).
     * Member: told when someone else paid for them.
     */
    private function notify(Contribution $contribution, User $payer): void
    {
        $owner = $contribution->event->owner;

        if ($contribution->method->isViaRazorpay() && ! $payer->is($owner)) {
            $owner->notify(new PaymentReceived($contribution));
        }

        if (! $payer->is($contribution->user)) {
            $contribution->user->notify(new PaidOnYourBehalf($contribution));
        }
    }
}
