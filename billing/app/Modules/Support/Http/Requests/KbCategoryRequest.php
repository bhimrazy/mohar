<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\KbCategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KbCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.kb.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name')))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category instanceof KbCategory ? $category->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('support_kb_categories', 'slug')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('support_kb_categories', 'id')->whereNull('parent_id'),
                function (string $attribute, mixed $value, Closure $fail) use ($id): void {
                    if ($id !== null && (int) $value === $id) {
                        $fail(__('A category cannot be its own parent.'));
                    }
                }],
            'is_hidden' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'slug' => $this->string('slug')->toString(),
            'description' => $this->input('description'),
            'parent_id' => $this->filled('parent_id') ? $this->integer('parent_id') : null,
            'is_hidden' => $this->boolean('is_hidden'),
            'sort_order' => $this->integer('sort_order'),
        ];
    }
}
