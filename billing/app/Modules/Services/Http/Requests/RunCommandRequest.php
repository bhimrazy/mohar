<?php

namespace App\Modules\Services\Http\Requests;

use App\Modules\Services\Enums\ModuleCommandName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('services.services.provision') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'command' => ['required', Rule::enum(ModuleCommandName::class)],
            'reason' => ['nullable', 'string', 'max:200'],
            'password' => [Rule::requiredIf($this->input('command') === ModuleCommandName::ChangePassword->value), 'nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    public function command(): ModuleCommandName
    {
        return ModuleCommandName::from($this->string('command')->toString());
    }

    /**
     * @return array<string, mixed>
     */
    public function commandArgs(): array
    {
        return match ($this->command()) {
            ModuleCommandName::Suspend => ['reason' => 'staff:'.($this->string('reason')->trim()->toString() ?: 'suspended by staff')],
            ModuleCommandName::ChangePassword => ['password' => $this->string('password')->toString()],
            default => [],
        };
    }
}
