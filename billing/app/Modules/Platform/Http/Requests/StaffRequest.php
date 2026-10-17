<?php

namespace App\Modules\Platform\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('core.staff.manage') ?? false;
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
        $staff = $this->route('staff');
        $creating = ! $staff instanceof User;

        return [
            'mode' => [Rule::requiredIf($creating), Rule::in(['invite', 'password'])],
            'name' => [Rule::requiredIf(! $creating || $this->input('mode') === 'password'), 'nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($creating ? null : $staff->id)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'password' => [Rule::requiredIf($creating && $this->input('mode') === 'password'), 'nullable', 'string', Password::min(12)->letters()->numbers()],
            'is_active' => [Rule::requiredIf(! $creating), 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.unique' => __('A login with this email address already exists.')];
    }
}
