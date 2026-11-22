<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of POST /cash-requests/{cashRequest}/reject: `{ "reason"?: "Not received" }`.
 */
class RejectCashRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('cashRequest'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:250'],
        ];
    }
}
