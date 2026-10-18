<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Core\Models\Role;
use App\Modules\Platform\Services\PermissionGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('core.roles.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role instanceof Role ? $role->id : null)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'distinct', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! app(PermissionGuard::class)->isKnown($value)) {
                    $fail(__('":value" is not a known permission.', ['value' => is_scalar($value) ? (string) $value : '?']));
                }
            }],
        ];
    }
}
