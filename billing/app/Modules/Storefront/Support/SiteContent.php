<?php

namespace App\Modules\Storefront\Support;

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
            'prices' => ['tax_inclusive' => [], 'notes' => []],
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
        $result['prices']['notes'] = array_filter((array) $result['prices']['notes'], fn (mixed $note): bool => is_string($note) && trim($note) !== '');

        return $result;
    }
}
