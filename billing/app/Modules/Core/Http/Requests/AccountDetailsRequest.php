<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\ClientContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountDetailsRequest extends FormRequest
{
    public function authorize(ClientContext $context): bool
    {
        return $context->can('profile');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ClientContext $context): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            // Staff signed in as the client cannot change the account email (spec §10).
            'email' => $this->session()->has(ActivityLogger::IMPERSONATOR_SESSION_KEY)
                ? ['required', 'email', 'max:255', Rule::in([$context->client()->email])]
                : ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_1' => ['required', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ];
    }
}
