<?php

namespace App\Modules\Storefront\Services;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Support\PublicHelpDirectory;
use App\Modules\Storefront\Support\StorefrontCache;
use Illuminate\Support\Facades\Route;

/**
 * Props for the home page, direction A (docs/design/home/home-page.md): the hero with domain search and the most
 * popular plan, then the host's sections in the host's order. Sections with nothing to show are left out.
 */
class HomePage
{
    public function __construct(
        private Catalogue $catalogue,
        private PriceDisplay $prices,
        private PublicHelpDirectory $help,
        private StorefrontCache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $content  normalised site content
     * @return array<string, mixed>
     */
    public function props(array $content, string $currency, bool $signedIn, bool $preview = false): array
    {
        $build = fn (): array => $this->build($content, $currency, $signedIn);

        return $preview ? $build() : $this->cache->remember("home.{$currency}.".(int) $signedIn, $build);
    }

    /**
     * The headline visitors see: the host's own, else a neutral placeholder with the company name. Never the
     * product's tagline (product standards, 2026-10-18).
     *
     * @param  array<string, mixed>  $content
     */
    public static function headline(array $content): string
    {
        $headline = trim((string) $content['hero']['headline']);

        return $headline !== '' ? $headline : 'Hosting and domains from '.app(BrandProvider::class)->brand()->name;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function build(array $content, string $currency, bool $signedIn): array
    {
        $display = $this->prices->forCurrency($currency, $content);
        $groups = $this->catalogue->groups($currency, $display);
        $domains = $this->catalogue->domains($currency, $display);
        $featuredIds = $content['featured']['group_ids'];
        $featured = array_values(array_filter($groups, fn (array $group): bool => in_array($group['id'], $featuredIds, true)));
        $featured = $featured !== [] ? $featured : array_slice($groups, 0, 1);
        $featuredGroupIds = array_column($featured, 'id');
        $enabled = array_column($content['sections'], 'enabled', 'key');

        $heroPlan = null;

        foreach ($featured as $group) {
            foreach ($group['plans'] as $plan) {
                if ($plan['featured'] && $heroPlan === null) {
                    $heroPlan = ['group' => $group['name'], 'compare_url' => $group['url'], 'plan' => $plan];
                }
            }
        }

        $cheapest = $this->cheapest($groups);
        $sections = [
            'trust_facts' => $content['trust_facts'],
            'why_us' => $content['why_us'],
            'featured_plans' => array_map(fn (array $group): array => [
                ...$group,
                'default_cycle' => $this->defaultCycle($group['plans'], $content['featured']['cycle']),
            ], $featured),
            'more_products' => [
                ...array_values(array_map(
                    fn (array $group): array => ['name' => $group['name'], 'tagline' => $group['tagline'], 'url' => $group['url'], 'from' => $group['from'], 'count' => count($group['plans'])],
                    array_filter($groups, fn (array $group): bool => ! in_array($group['id'], $featuredGroupIds, true)),
                )),
                ...$this->domainProduct($domains),
            ],
            'help' => true,
            'news' => $this->news($content['news'], $signedIn),
            'cta_band' => $cheapest === null || trim((string) $content['cta']['headline']) === '' ? null : [
                'headline' => $content['cta']['headline'],
                'button' => $content['cta']['button'],
                'from' => $cheapest,
                'url' => Route::has('store.index') ? route('store.index') : null,
            ],
            'payments' => true,
        ];

        $order = [];

        foreach ($content['sections'] as $section) {
            $value = $sections[$section['key']];

            if ($enabled[$section['key']] && $value !== [] && $value !== null) {
                $order[] = $section['key'];
            }
        }

        return [
            'hero' => [
                'eyebrow' => $content['hero']['eyebrow'],
                'headline' => self::headline($content),
                'subtext' => $content['hero']['subtext'],
                'domain_search' => $content['hero']['domain_search'],
                'plan' => $heroPlan,
            ],
            'domains' => [
                'featured' => array_slice(array_values(array_filter($domains, fn (array $row): bool => $row['featured'] && $row['register'] !== null)), 0, 8),
                'search_url' => Route::has('store.domains') ? route('store.domains') : null,
                'transfer_url' => Route::has('store.domains') ? route('store.domains', ['transfer' => 1]) : null,
                'pricing_url' => route('storefront.domain-pricing'),
            ],
            'sections' => $order,
            'trust_facts' => $sections['trust_facts'],
            'why_us' => $sections['why_us'],
            'cta_band' => $sections['cta_band'],
            'featured_plans' => $sections['featured_plans'],
            'more_products' => $sections['more_products'],
            'news' => $sections['news'],
            'tax_note' => $display['note'],
            'currency' => $currency,
        ];
    }

    /**
     * The lowest "from" price across groups, preferring per-month figures (the band's "Hosting from …").
     *
     * @param  list<array{from: array{amount: int, cycle: string, per_month: bool, setup: int}|null}>  $groups
     * @return array{amount: int, cycle: string, per_month: bool, setup: int}|null
     */
    private function cheapest(array $groups): ?array
    {
        $froms = array_values(array_filter(array_column($groups, 'from')));
        usort($froms, fn (array $a, array $b): int => [$b['per_month'], $a['amount']] <=> [$a['per_month'], $b['amount']]);

        return $froms[0] ?? null;
    }

    /**
     * The host's first cycle when any plan offers it, else annual, else the shortest offered (spec §5.3).
     *
     * @param  list<array{pricing: list<array{cycle: string}>}>  $plans
     */
    private function defaultCycle(array $plans, string $preferred): ?string
    {
        $offered = array_unique(array_merge(...array_map(fn (array $plan): array => array_column($plan['pricing'], 'cycle'), $plans)));

        foreach ([$preferred, 'annually', 'monthly', 'quarterly', 'semiannually', 'biennially', 'triennially'] as $cycle) {
            if (in_array($cycle, $offered, true)) {
                return $cycle;
            }
        }

        return $offered[0] ?? null;
    }

    /**
     * @param  list<array{tld: string, register: int|null}>  $domains
     * @return list<array{name: string, tagline: string, url: string|null, from: array{amount: int, cycle: string, per_month: bool, setup: int}|null, count: int}>
     */
    private function domainProduct(array $domains): array
    {
        $prices = array_filter(array_column($domains, 'register'), is_int(...));

        if ($prices === []) {
            return [];
        }

        return [[
            'name' => 'Domains',
            'tagline' => 'Register or transfer '.implode(', ', array_slice(array_column($domains, 'tld'), 0, 5)),
            'url' => Route::has('store.domains') ? route('store.domains') : null,
            'from' => ['amount' => min($prices), 'cycle' => 'annually', 'per_month' => false, 'setup' => 0],
            'count' => count($domains),
        ]];
    }

    /**
     * @param  array{announcements: int, articles: int, status: bool}  $settings
     * @return array<string, mixed>|null
     */
    private function news(array $settings, bool $signedIn): ?array
    {
        $announcements = $settings['announcements'] > 0 ? $this->help->recentAnnouncements($settings['announcements']) : [];
        $articles = $settings['articles'] > 0 ? $this->help->popularArticles($settings['articles']) : [];
        $status = $settings['status'] ? $this->help->networkStatus($signedIn) : null;

        if ($announcements === [] && $articles === [] && $status === null) {
            return null;
        }

        return [
            'announcements' => $announcements,
            'articles' => $articles,
            'status' => $status,
            'announcements_url' => Route::has('support.announcements.index') ? route('support.announcements.index') : null,
            'kb_url' => Route::has('support.kb.index') ? route('support.kb.index') : null,
            'status_url' => Route::has('support.network.index') ? route('support.network.index') : null,
        ];
    }
}
