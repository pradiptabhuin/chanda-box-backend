<?php

namespace App\Http\Requests\Event;

use App\Enums\EventStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of PUT /events/{event}/status: `{ "status": "active" | "closed" | "cancelled" }`.
 */
class UpdateEventStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(EventStatus::class)],
        ];
    }

    public function status(): EventStatus
    {
        return EventStatus::from($this->validated('status'));
    }
}
