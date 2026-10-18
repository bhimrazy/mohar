<?php

namespace App\Modules\Support\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CannedReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.canned.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('support_canned_reply_categories', 'id')],
            'body' => ['required', 'string', 'max:20000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
