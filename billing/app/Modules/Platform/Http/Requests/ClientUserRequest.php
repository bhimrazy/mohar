<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Core\Support\ClientContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('core.users.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $inviting = $this->route('user') === null;

        return [
            'email' => [Rule::requiredIf($inviting), 'string', 'email', 'max:255'],
            'name' => [Rule::requiredIf($inviting), 'string', 'max:100'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(ClientContext::AREAS)],
        ];
    }
}
