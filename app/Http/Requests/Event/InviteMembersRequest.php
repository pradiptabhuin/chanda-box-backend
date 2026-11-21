<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /events/{event}/invitations: `{ "phones": ["9800000005", ...] }`.
 *
 * Individual numbers aren't validated here: invalid ones are skipped and reported back
 * in the response, so one typo doesn't block the whole list.
 */
class InviteMembersRequest extends FormRequest
{
    public const MAX_PHONES = 100;

    /**
     * Only the owner can invite (checked before validation).
     */
    public function authorize(): bool
    {
        return $this->user()->can('invite', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phones' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHONES],
            'phones.*' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return list<string>
     */
    public function phones(): array
    {
        return array_values(array_filter($this->validated('phones'), fn (?string $phone) => filled($phone)));
    }
}
