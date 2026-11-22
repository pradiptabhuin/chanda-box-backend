<?php

namespace App\Http\Requests\Payment;

use App\Enums\MemberStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared body of the contribution endpoints: `{ "amount": 1001, "member_id"?: 12 }`.
 * `member_id` lets the owner pay on behalf of a joined member.
 */
abstract class ContributionRequest extends FormRequest
{
    /**
     * Largest single contribution this endpoint takes, in rupees.
     */
    abstract protected function maxAmount(): int;

    /**
     * Owner or joined member of an active event; only the owner may name a member (checked before validation).
     */
    public function authorize(): bool
    {
        $event = $this->event();

        return $this->user()->can('contribute', $event)
            && (! $this->filled('member_id') || $this->user()->can('payOnBehalf', $event));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$this->maxAmount(), 'decimal:0,2'],
            'member_id' => [
                'nullable',
                'integer',
                Rule::exists('event_members', 'user_id')
                    ->where('event_id', $this->event()->id)
                    ->where('status', MemberStatus::Joined->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['member_id.exists' => 'This person is not a member of the event.'];
    }

    public function amount(): float
    {
        return (float) $this->validated('amount');
    }

    /**
     * The member being paid for, or null when paying for yourself.
     */
    public function member(): ?User
    {
        $memberId = $this->validated('member_id');

        return $memberId === null || (int) $memberId === $this->user()->id ? null : User::findOrFail($memberId);
    }

    public function event(): Event
    {
        return $this->route('event');
    }
}
