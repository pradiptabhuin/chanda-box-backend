<?php

namespace App\Http\Requests\Device;

use App\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of POST /me/device-token (`token` + `platform`) and DELETE /me/device-token (`token`).
 */
class DeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'platform' => [Rule::requiredIf($this->isMethod('post')), Rule::enum(DevicePlatform::class)],
        ];
    }
}
