<?php

namespace App\Modules\Kyc\Http\Requests;

use App\Modules\Core\Support\ClientContext;
use App\Modules\Kyc\Enums\DocumentType;
use App\Support\Integrations\KeepsSecretsOutOfOldInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An identity document upload. The image type is read from the file's bytes (mimetypes uses finfo), never from its
 * name, and SVG is refused (it can carry script and we have no sanitiser). The controller scopes the client account.
 */
class DocumentRequest extends FormRequest
{
    use KeepsSecretsOutOfOldInput;

    public const IMAGE_TYPES = 'mimetypes:image/jpeg,image/png,image/webp';

    public function authorize(): bool
    {
        return $this->user() !== null && app(ClientContext::class)->can('profile');
    }

    /**
     * The document number is personal data: it is never flashed to the session with the old input.
     *
     * @return list<string>
     */
    protected function secretInputPaths(): array
    {
        return ['number'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = DocumentType::tryFrom($this->string('type')->toString());
        $image = ['file', self::IMAGE_TYPES, 'max:'.(int) config('kyc.max_upload_kb', 5120)];

        return [
            'type' => ['required', Rule::enum(DocumentType::class)],
            'number' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}\-\/ .]*$/u'],
            'front' => ['required', ...$image],
            'back' => [$type?->hasBack() ?? true ? 'required' : 'nullable', ...$image],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => __('Choose the document type.'),
            'number.required' => __('Enter the document number.'),
            'number.min' => __('Enter the full document number.'),
            'number.max' => __('A document number has at most 40 characters.'),
            'front.required' => __('Add a photo of the front of the document.'),
            'back.required' => __('Add a photo of the back of the document.'),
            'front.file' => __('Add a photo of the front of the document.'),
            'back.file' => __('Add a photo of the back of the document.'),
            'front.mimetypes' => __('Upload a photo or scan as JPEG, PNG or WebP.'),
            'back.mimetypes' => __('Upload a photo or scan as JPEG, PNG or WebP.'),
            'front.max' => __('The image must be 5 MB or smaller.'),
            'back.max' => __('The image must be 5 MB or smaller.'),
            'number.regex' => __('Use letters, digits, spaces, dashes, dots or slashes.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['front' => __('front image'), 'back' => __('back image'), 'number' => __('document number')];
    }
}
