<?php

namespace App\Modules\Themes\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Raster images only. SVG is rejected outright: it can carry script, and we don't ship a sanitiser.
 */
class BrandAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('themes.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->route('kind')) {
            'favicon' => ['file' => ['required', File::types(['png', 'ico'])->max(128), 'dimensions:max_width=512,max_height=512']],
            'logo-email' => ['file' => ['required', File::image()->types(['png', 'jpg', 'jpeg'])->max(512), 'dimensions:min_height=16,max_width=2000,max_height=1000']],
            'mark' => ['file' => ['required', File::image()->types(['png', 'webp'])->max(256), 'dimensions:min_width=32,max_width=1024,max_height=1024,ratio=1']],
            default => ['file' => ['required', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(512), 'dimensions:min_height=16,max_width=2000,max_height=1000']],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // The email logo is converted to PNG and flattened onto white in the browser; the server takes only the result.
        if ($this->route('kind') === 'logo-email') {
            return [
                'file.mimes' => 'The email logo must be a PNG or JPEG image (other formats, including WebP and SVG, are not accepted).',
                'file.image' => 'The email logo must be a PNG or JPEG image (other formats, including WebP and SVG, are not accepted).',
                'file.max' => 'The email logo must be 512 KB or smaller.',
                'file.dimensions' => 'The email logo must be at least 16 px high and at most 2000 × 1000 px.',
            ];
        }

        return [
            'file.mimes' => 'Upload a PNG, JPG or WebP image (SVG is not accepted).',
            'file.image' => 'Upload a PNG, JPG or WebP image (SVG is not accepted).',
            'file.max' => 'The image is too large.',
            'file.dimensions' => 'The image dimensions are outside the allowed range.',
        ];
    }
}
