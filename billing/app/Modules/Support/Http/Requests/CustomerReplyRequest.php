<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Core\Support\ClientContext;
use App\Modules\Support\Http\Requests\Concerns\ValidatesAttachments;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CustomerReplyRequest extends FormRequest
{
    use ValidatesAttachments;

    /**
     * Client-area requests need the "support" permission on the current account; guest requests
     * are authorised by the access key in the controller.
     */
    public function authorize(): bool
    {
        return ! $this->routeIs('client.*') || app(ClientContext::class)->can('support');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65000'],
            ...$this->attachmentRules(staff: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attachmentMessages();
    }
}
