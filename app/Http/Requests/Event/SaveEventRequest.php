<?php

namespace App\Http\Requests\Event;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Body of POST /events and PUT /events/{event}: the full event, including its curriculum.
 */
class SaveEventRequest extends FormRequest
{
    private const MAX_AMOUNT = 9_999_999_999.99;

    /**
     * Anyone signed in can create; only the owner can update (checked before validation).
     */
    public function authorize(): bool
    {
        $event = $this->route('event');

        return ! $event instanceof Event || $this->user()->can('update', $event);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:250'],
            'proposed_amount' => ['required', 'numeric', 'min:1', 'max:'.self::MAX_AMOUNT, 'decimal:0,2'],
            'target_amount' => ['nullable', 'numeric', 'gte:proposed_amount', 'max:'.self::MAX_AMOUNT, 'decimal:0,2'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'curriculum' => ['sometimes', 'array', 'max:50'],
            'curriculum.*.title' => ['required', 'string', 'max:250'],
            'curriculum.*.date' => ['required', 'date'],
            'curriculum.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The validated event with every date converted to the app timezone, so an ISO-8601
     * value like `2026-12-09T00:00:00Z` is stored as the same instant whatever APP_TIMEZONE is.
     *
     * @return array<string, mixed>
     */
    public function eventData(): array
    {
        $data = $this->validated();

        foreach (['start_date', 'end_date'] as $field) {
            $data[$field] = $this->toAppTimezone($data[$field] ?? null);
        }

        if (isset($data['curriculum'])) {
            $data['curriculum'] = array_map(
                fn (array $item) => [...$item, 'date' => $this->toAppTimezone($item['date'])],
                $data['curriculum'],
            );
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_amount.gte' => 'The target amount must be at least the proposed amount.',
            'end_date.after_or_equal' => 'The end date must be on or after the start date.',
            'curriculum.*.title.required' => 'Each programme item needs a title.',
            'curriculum.*.date.required' => 'Each programme item needs a date.',
        ];
    }

    private function toAppTimezone(?string $date): ?Carbon
    {
        return $date === null ? null : Carbon::parse($date)->setTimezone(config('app.timezone'));
    }
}
