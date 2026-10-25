<?php

namespace App\Modules\Orders\Http\Requests;

use App\Models\User;
use App\Modules\Core\Support\Settings;
use App\Support\Countries;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Checkout step: gateway, terms, notes and — for guests — inline registration.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $settings = app(Settings::class);
        $rules = [
            'payment_gateway' => ['required', 'string', 'max:50'],
            'accept_tos' => $settings->get('orders.require_tos', true) ? ['accepted'] : ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'expected_net_total' => ['nullable', 'integer'],
            'apply_credit' => ['nullable', 'boolean'],
        ];

        if ($this->user() === null) {
            $rules += [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'company_name' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
                'country' => ['nullable', 'string', Rule::in(Countries::CODES)],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_tos.accepted' => __('Accept the terms of service to place your order.'),
            'payment_gateway.required' => __('Choose a payment method.'),
            'email.unique' => __('An account with this email already exists. Log in to continue.'),
            'email.required' => __('Enter your email address.'),
            'email.email' => __('Enter a valid email address, like name@example.com.'),
            'first_name.required' => __('Enter your first name.'),
            'last_name.required' => __('Enter your last name.'),
            'password.required' => __('Choose a password.'),
            'password.confirmed' => __('The passwords do not match.'),
            'country.in' => __('Choose a country from the list.'),
            'idempotency_key.required' => __('Reload the page and try again.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }

        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper(trim((string) $this->input('country')))]);
        }
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, password: string, company_name: ?string, country: ?string}|null
     */
    public function registration(): ?array
    {
        if ($this->user() !== null) {
            return null;
        }

        return [
            'first_name' => $this->string('first_name')->trim()->toString(),
            'last_name' => $this->string('last_name')->trim()->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'company_name' => $this->filled('company_name') ? $this->string('company_name')->trim()->toString() : null,
            'country' => $this->filled('country') ? $this->string('country')->upper()->toString() : null,
        ];
    }
}
