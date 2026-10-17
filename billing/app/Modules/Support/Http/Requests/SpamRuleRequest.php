<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\SpamRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpamRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.inbound.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'field' => ['required', Rule::in(SpamRule::FIELDS)],
            'match' => ['required', Rule::in(SpamRule::MATCHES)],
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
