<?php

namespace App\Modules\Domains\Http\Requests;

use App\Modules\Domains\Models\NpRegistration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A registrant document for a .np request. The registry takes images up to 800 KB; SVG is refused (it can carry
 * script and we have no sanitiser). The client area authorizes and scopes the domain in the controller.
 */
class NpDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->string('applicant_type')->toString();

        return [
            'applicant_type' => ['required', Rule::in(NpRegistration::APPLICANT_TYPES)],
            'kind' => ['required', Rule::in(NpRegistration::REQUIRED_DOCUMENTS[$type] ?? [])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,gif', 'max:800'],
        ];
    }
}
