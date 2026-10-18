<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\KbArticle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KbArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.kb.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug((string) ($this->input('slug') ?: $this->input('title')))]);

        if (is_string($this->input('tags'))) {
            $this->merge(['tags' => array_values(array_filter(array_map('trim', explode(',', (string) $this->input('tags')))))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $article = $this->route('article');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('support_kb_articles', 'slug')->ignore($article instanceof KbArticle ? $article->id : null)],
            'body' => ['required', 'string', 'max:200000'],
            'is_published' => ['boolean'],
            'is_private' => ['boolean'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', Rule::exists('support_kb_categories', 'id')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'slug' => $this->string('slug')->toString(),
            'body' => $this->string('body')->toString(),
            'is_published' => $this->boolean('is_published'),
            'is_private' => $this->boolean('is_private'),
            'tags' => array_values(array_map('strval', (array) $this->validated('tags', []))),
            'sort_order' => $this->integer('sort_order'),
        ];
    }

    /**
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('categories', [])));
    }
}
