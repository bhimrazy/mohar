<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Requests\Concerns\ValidatesSlug;
use App\Modules\Catalog\Models\ProductGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductGroupRequest extends FormRequest
{
    use ValidatesSlug;

    /** First path segments under /store that belong to the store itself, so a group page cannot use them. */
    public const RESERVED_SLUGS = ['cart', 'checkout', 'domains', 'currency', 'orders', 'products'];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.products.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSlug();

        if (! $this->boolean('restrict_gateways')) {
            $this->merge(['allowed_gateways' => null]);
        } elseif (is_string($gateways = $this->input('allowed_gateways'))) {
            $this->merge(['allowed_gateways' => array_values(array_filter(array_map('trim', explode(',', $gateways))))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var ProductGroup|null $group */
        $group = $this->route('group');

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                ...$this->slugRules(),
                Rule::unique('product_groups', 'slug')->ignore($group?->id),
                Rule::notIn(self::RESERVED_SLUGS),
            ],
            'headline' => ['nullable', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_hidden' => ['boolean'],
            'restrict_gateways' => ['boolean'],
            'allowed_gateways' => ['nullable', 'array', 'required_if_accepted:restrict_gateways', 'max:50'],
            'allowed_gateways.*' => ['string', 'max:50', 'distinct', 'regex:/^[a-z0-9_.-]+$/i'],
            'order_form_template' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->slugMessages(),
            'slug.not_in' => __('This slug is reserved for the store (:words). Enter a different slug.', ['words' => implode(', ', self::RESERVED_SLUGS)]),
            'allowed_gateways.required_if_accepted' => __('Enter at least one gateway, or allow every gateway.'),
            'allowed_gateways.*.regex' => __('Gateway keys may only contain letters, numbers, dots, dashes and underscores.'),
        ];
    }

    /**
     * @return array{name: string, slug: string, headline: ?string, tagline: ?string, description: ?string, is_hidden: bool, allowed_gateways: list<string>|null, order_form_template: ?string}
     */
    public function groupData(): array
    {
        /** @var array{name: string, slug: string, headline?: ?string, tagline?: ?string, description?: ?string, allowed_gateways?: list<string>|null, order_form_template?: ?string} $data */
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'headline' => $data['headline'] ?? null,
            'tagline' => $data['tagline'] ?? null,
            'description' => $data['description'] ?? null,
            'is_hidden' => $this->boolean('is_hidden'),
            'allowed_gateways' => $data['allowed_gateways'] ?? null,
            'order_form_template' => $data['order_form_template'] ?? null,
        ];
    }
}
