<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Body of POST /me/photo: multipart with a `photo` image (the app crops and resizes it first).
 */
class UpdateProfilePhotoRequest extends FormRequest
{
    public const MAX_KILOBYTES = 5120;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.self::MAX_KILOBYTES,
                'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.max' => 'The photo must be 5 MB or smaller.',
            'photo.dimensions' => 'The photo must be between 100 and 4096 pixels on each side.',
        ];
    }

    public function photo(): UploadedFile
    {
        return $this->file('photo');
    }
}
