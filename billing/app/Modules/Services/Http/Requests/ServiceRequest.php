<?php

namespace App\Modules\Services\Http\Requests;

use App\Data\Services\Provisioning\UsernamePolicy;
use App\Enums\BillingCycle;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ServiceDrivers;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Staff edit of a service. Money fields are integer minor units. A changed username must pass the username policy
 * of the service's driver, when the driver declares one.
 */
class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.services.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => $this->filled('domain') ? strtolower(trim((string) $this->input('domain'))) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'dedicated_ip' => ['nullable', 'ip'],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'first_payment_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'recurring_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'next_due_date' => ['nullable', 'date'],
            'next_invoice_date' => ['nullable', 'date', 'after_or_equal:next_due_date'],
            'suspend_exempt_until' => ['nullable', 'date'],
            'suspend_days_override' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'terminate_days_override' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'recurring_cycles_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'do_not_renew' => ['boolean'],
            'terminate_at_period_end' => ['boolean'],
            'payment_gateway' => ['nullable', 'string', 'max:50'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(ServiceDrivers $drivers): array
    {
        return [function (Validator $validator) use ($drivers): void {
            $service = $this->route('service');
            $username = $this->string('username')->toString();

            if (! $service instanceof Service || $username === '' || $username === $service->username) {
                return;
            }

            // Only a driver that declares a policy constrains staff-typed usernames; manual services stay free-form.
            $policy = $drivers->displayDriverFor($service)->metadata()->usernamePolicy;
            $reason = $policy?->violation($username);

            if ($reason !== null) {
                $validator->errors()->add('username', match ($reason) {
                    UsernamePolicy::TOO_LONG => __('The username can be at most :max characters on this server.', ['max' => $policy->maxLength]),
                    UsernamePolicy::INVALID_CHARACTERS => __('The username contains characters this server does not accept.'),
                    default => __('This username is reserved on the server; choose another.'),
                });
            }
        }];
    }
}
