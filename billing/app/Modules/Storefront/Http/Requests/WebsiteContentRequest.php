<?php

namespace App\Modules\Storefront\Http\Requests;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Currencies\CurrencyDirectory;
use App\Modules\Storefront\Support\SiteContent;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Website content as plain fields (spec §6, criterion 24): text with length limits and no markup, choices from fixed
 * lists, groups that Catalog lists as visible, currencies that exist. Never HTML, JSON or internal keys.
 */
class WebsiteContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('storefront.content.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $plain = 'not_regex:/<\s*\/?[a-z!]/i';
        $currencies = array_map(fn ($display): string => $display->code, app(CurrencyDirectory::class)->displays());
        $groups = array_column(app(PriceBook::class)->storefront($currencies[0] ?? 'USD'), 'id');

        return [
            'hero.eyebrow' => ['nullable', 'string', 'max:40', $plain],
            'hero.headline' => ['required', 'string', 'max:80', $plain],
            'hero.subtext' => ['nullable', 'string', 'max:200', $plain],
            'hero.domain_search' => ['required', 'boolean'],
            'sections' => ['required', 'array', 'size:'.count(SiteContent::SECTIONS)],
            'sections.*.key' => ['required', 'distinct', Rule::in(array_keys(SiteContent::SECTIONS))],
            'sections.*.enabled' => ['required', 'boolean'],
            'trust_facts' => ['present', 'array', 'max:4'],
            'trust_facts.*.label' => ['required', 'string', 'max:30', $plain],
            'trust_facts.*.value' => ['required', 'string', 'max:40', $plain],
            'cta.headline' => ['nullable', 'string', 'max:80', $plain],
            'cta.button' => ['required', 'string', 'max:24', $plain],
            'why_us' => ['present', 'array', 'max:6'],
            'why_us.*.icon' => ['required', Rule::in(SiteContent::ICONS)],
            'why_us.*.title' => ['required', 'string', 'max:40', $plain],
            'why_us.*.text' => ['required', 'string', 'max:140', $plain],
            'featured.group_ids' => ['present', 'array', 'max:3'],
            'featured.group_ids.*' => ['integer', 'distinct', Rule::in($groups)],
            'featured.cycle' => ['required', Rule::in(SiteContent::CYCLES)],
            'news.announcements' => ['required', 'integer', 'between:0,4'],
            'news.articles' => ['required', 'integer', 'between:0,6'],
            'news.status' => ['required', 'boolean'],
            'header.products' => ['required', Rule::in(SiteContent::PRODUCT_MENUS)],
            'header.menu_label' => ['required', 'string', 'max:24', $plain],
            'seo.title' => ['nullable', 'string', 'max:60', $plain],
            'seo.description' => ['nullable', 'string', 'max:160', $plain],
            'seo.noindex' => ['required', 'boolean'],
            'legal.terms_url' => ['nullable', 'url:https', 'max:255'],
            'legal.privacy_url' => ['nullable', 'url:https', 'max:255'],
            'legal.refund_url' => ['nullable', 'url:https', 'max:255'],
            'prices.tax_inclusive' => ['present', 'array'],
            'prices.tax_inclusive.*' => ['distinct', Rule::in($currencies)],
            'prices.notes' => ['present', 'array'],
            'prices.notes.*' => ['nullable', 'string', 'max:80', $plain],
            'prices.markets' => ['present', 'array'],
            'prices.markets.*' => ['nullable', 'string', Rule::in(Countries::CODES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'not_regex' => 'Use plain text without HTML tags.',
            'featured.group_ids.*.in' => 'Choose a product group that is visible in the store.',
            'hero.headline.required' => 'Write a headline. It is the first thing visitors read.',
        ];
    }

    /**
     * Content with empty strings for blank optional fields; notes only for currencies that exist.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        $data = $this->validated();
        $currencies = array_map(fn ($display): string => $display->code, app(CurrencyDirectory::class)->displays());
        $data['prices']['markets'] = array_intersect_key((array) $data['prices']['markets'], array_flip($currencies));
        $data['prices']['notes'] = array_intersect_key((array) $data['prices']['notes'], array_flip($currencies));
        array_walk_recursive($data, function (mixed &$value): void {
            $value = $value === null ? '' : $value;
        });

        return SiteContent::normalise($data);
    }
}
