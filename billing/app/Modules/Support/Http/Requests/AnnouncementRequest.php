<?php

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\Announcement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('support.announcements.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => Str::slug((string) ($this->input('slug') ?: $this->input('title')))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $announcement = $this->route('announcement');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('support_announcements', 'slug')->ignore($announcement instanceof Announcement ? $announcement->id : null)],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:200000'],
            'is_published' => ['boolean'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        $published = $this->boolean('is_published');

        return [
            'title' => $this->string('title')->toString(),
            'slug' => $this->string('slug')->toString(),
            'excerpt' => $this->input('excerpt'),
            'body' => $this->string('body')->toString(),
            'is_published' => $published,
            'published_at' => $this->filled('published_at') ? $this->date('published_at') : ($published ? now() : null),
        ];
    }
}
