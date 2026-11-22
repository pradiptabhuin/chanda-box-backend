<?php

namespace App\Http\Requests\Payment;

/**
 * Body of POST /events/{event}/cash-requests: `{ "amount": 1001, "member_id"?: 12, "note"?: "..." }`.
 */
class StoreCashRequestRequest extends ContributionRequest
{
    public const MAX_AMOUNT = 10_000_000;

    protected function maxAmount(): int
    {
        return self::MAX_AMOUNT;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'note' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function note(): ?string
    {
        return $this->validated('note');
    }
}
