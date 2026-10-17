<?php

namespace App\Modules\Catalog\Http\Requests\Concerns;

use Illuminate\Support\Str;

trait ValidatesSlug
{
    /**
     * Fill the slug from the name when left empty and normalise it.
     */
    protected function prepareSlug(): void
    {
        $slug = trim((string) $this->input('slug', ''));

        $this->merge(['slug' => Str::slug($slug !== '' ? $slug : (string) $this->input('name', ''))]);
    }

    /**
     * @return list<string>
     */
    protected function slugRules(): array
    {
        return ['required_with:name', 'nullable', 'string', 'max:120', 'alpha_dash:ascii', 'regex:/[a-z]/i'];
    }

    /**
     * @return array<string, string>
     */
    protected function slugMessages(): array
    {
        return [
            'slug.regex' => __('The slug must contain at least one letter.'),
            'slug.unique' => __('This slug is already used. Change the name or enter a different slug.'),
        ];
    }
}
