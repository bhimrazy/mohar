<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\Department;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.departments.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $department = $this->route('department');
        $id = $department instanceof Department ? $department->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('support_departments', 'slug')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'email' => ['required', 'email', 'max:255', Rule::unique('support_departments', 'email')->ignore($id)],
            'is_hidden' => ['boolean'],
            'clients_only' => ['boolean'],
            'replies_only_by_email' => ['boolean'],
            'send_autoresponse' => ['boolean'],
            'autoresponse_message' => ['nullable', 'string', 'max:10000'],
            'prevent_client_close' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['integer', Rule::exists('users', 'id')->where('is_staff', true)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        return [
            ...$this->safe()->except(['staff']),
            'sort_order' => $this->integer('sort_order'),
            ...collect(['is_hidden', 'clients_only', 'replies_only_by_email', 'send_autoresponse', 'prevent_client_close'])
                ->mapWithKeys(fn (string $key): array => [$key => $this->boolean($key)])->all(),
        ];
    }

    /**
     * @return list<int>
     */
    public function staffIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('staff', [])));
    }
}
