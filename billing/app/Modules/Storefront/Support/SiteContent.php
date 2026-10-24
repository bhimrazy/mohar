<?php

namespace App\Modules\Storefront\Support;

use App\Support\Countries;

/**
 * The website content schema (version 1): what the host controls in Admin → Website. Stored per revision as JSON;
 * normalise() is the only way content is read, so unknown keys are dropped and missing ones take defaults.
 * Prices are never stored here (they come live from Catalog and Domains).
 */
final class SiteContent
{
    public const SCHEMA = 1;

    /** Home sections the host can switch off and reorder; the hero with domain search always comes first. */
    public const SECTIONS = [
        'trust_facts' => 'Trust facts',
        'why_us' => 'Why choose us',
        'featured_plans' => 'Featured plans',
        'more_products' => 'More products',
        'help' => 'How can we help',
        'news' => 'News and status',
        'cta_band' => 'Call to action band',
        'payments' => 'Ways to pay',
    ];

    public const ICONS = ['clock', 'shield', 'refresh', 'card', 'headset', 'server', 'globe', 'lock', 'zap', 'map-pin'];

    public const CYCLES = ['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'];

    public const PRODUCT_MENUS = ['auto', 'menu', 'links'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'hero' => ['eyebrow' => '', 'headline' => '', 'subtext' => '', 'domain_search' => true],
            'sections' => array_map(fn (string $key): array => ['key' => $key, 'enabled' => true], array_keys(self::SECTIONS)),
            'trust_facts' => [],
            'why_us' => [],
            'cta' => ['headline' => 'Ready to get online?', 'button' => 'See all plans'],
            'featured' => ['group_ids' => [], 'cycle' => 'annually'],
            'news' => ['announcements' => 2, 'articles' => 4, 'status' => true],
            'header' => ['products' => 'auto', 'menu_label' => 'Hosting'],
            'seo' => ['title' => '', 'description' => '', 'noindex' => false],
            'legal' => ['terms_url' => '', 'privacy_url' => '', 'refund_url' => ''],
            'prices' => ['tax_inclusive' => [], 'notes' => [], 'markets' => []],
        ];
    }

    /**
     * Stored or submitted content merged over the defaults: unknown keys dropped, every section listed once.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function normalise(array $content): array
    {
        $defaults = self::defaults();
        $result = [];

        foreach ($defaults as $key => $default) {
            $value = $content[$key] ?? $default;
            $result[$key] = is_array($default) && ! array_is_list($default) && is_array($value)
                ? array_intersect_key($value + $default, $default)
                : (is_array($value) ? $value : $default);
        }

        $seen = [];
        $sections = [];

        foreach ($result['sections'] as $section) {
            $key = is_array($section) ? ($section['key'] ?? null) : null;

            if (is_string($key) && isset(self::SECTIONS[$key]) && ! isset($seen[$key])) {
                $seen[$key] = true;
                $sections[] = ['key' => $key, 'enabled' => (bool) ($section['enabled'] ?? true)];
            }
        }

        // A section added in a later version joins at its default place: after the section that precedes it there.
        $previous = null;

        foreach (array_keys(self::SECTIONS) as $key) {
            if (! isset($seen[$key])) {
                $index = $previous === null ? false : array_search($previous, array_column($sections, 'key'), true);
                $at = $index === false ? 0 : $index + 1;
                array_splice($sections, $at, 0, [['key' => $key, 'enabled' => true]]);
            }

            $previous = $key;
        }

        $result['sections'] = $sections;
        $result['why_us'] = array_values(array_filter($result['why_us'], is_array(...)));
        $result['trust_facts'] = array_values(array_filter($result['trust_facts'], is_array(...)));
        $result['featured']['group_ids'] = array_values(array_map(intval(...), (array) $result['featured']['group_ids']));
        $result['prices']['tax_inclusive'] = array_values(array_filter((array) $result['prices']['tax_inclusive'], is_string(...)));
        // Currency => ISO country its prices are for; '' means sold across markets. A currency not listed uses the
        // storefront.tax_markets default.
        $result['prices']['markets'] = array_filter(
            (array) $result['prices']['markets'],
            fn (mixed $country, mixed $code): bool => is_string($code) && is_string($country) && ($country === '' || Countries::exists($country)),
            ARRAY_FILTER_USE_BOTH,
        );
        ksort($result['prices']['markets']);
        $result['prices']['notes'] = array_filter((array) $result['prices']['notes'], fn (mixed $note): bool => is_string($note) && trim($note) !== '');

        return $result;
    }

    /**
     * Top-level content keys that differ (for StorefrontPublished and the audit log).
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public static function changedSections(array $from, array $to): array
    {
        $from = self::normalise($from);
        $to = self::normalise($to);

        return array_values(array_filter(array_keys(self::defaults()), fn (string $key): bool => $from[$key] !== $to[$key]));
    }

    /**
     * Plain-language list of what differs between two versions, for the publish confirmation and the audit log.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public static function changes(array $from, array $to): array
    {
        $from = self::normalise($from);
        $to = self::normalise($to);
        $changes = [];
        $labels = [
            'hero.eyebrow' => 'Small label above the headline changed',
            'hero.headline' => 'Headline changed',
            'hero.subtext' => 'Text under the headline changed',
            'hero.domain_search' => 'Domain search in the headline turned '.(($to['hero']['domain_search'] ?? true) ? 'on' : 'off'),
            'trust_facts' => 'Trust facts changed',
            'why_us' => 'Why choose us points changed',
            'cta' => 'Call to action band text changed',
            'featured.group_ids' => 'Featured product groups changed',
            'featured.cycle' => 'Billing cycle shown first changed',
            'news' => 'News and status settings changed',
            'header' => 'Products in the header changed',
            'seo.title' => 'Page title changed',
            'seo.description' => 'Search description changed',
            'seo.noindex' => 'Search engines '.(($to['seo']['noindex'] ?? false) ? 'blocked' : 'allowed'),
            'legal' => 'Legal page links changed',
            'prices' => 'Price and tax display changed',
        ];

        foreach ($labels as $path => $label) {
            if (data_get($from, $path) !== data_get($to, $path)) {
                $changes[] = $label;
            }
        }

        $enabled = fn (array $content): array => array_column($content['sections'], 'enabled', 'key');

        foreach (self::SECTIONS as $key => $label) {
            if ($enabled($from)[$key] !== $enabled($to)[$key]) {
                $changes[] = $label.' turned '.($enabled($to)[$key] ? 'on' : 'off');
            }
        }

        if (array_column($from['sections'], 'key') !== array_column($to['sections'], 'key')) {
            $changes[] = 'Section order changed';
        }

        return $changes;
    }
}
