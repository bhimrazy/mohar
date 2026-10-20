<?php

namespace App\Modules\Storefront\Services;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainPricing;
use App\Modules\Core\Support\Settings;
use Illuminate\Support\Facades\Route;

/**
 * Live Catalog groups and Domains prices in one currency, shaped for the public site and converted to the price
 * display (with or without tax). Nothing here is typed by the host: every amount comes from PriceBook or
 * DomainPricing at render time (cached by the caller). Either module may be absent: its part is then empty.
 *
 * Per-month prices are shown only with `catalog.show_monthly_breakdown` on (spec §5.2); tax is added for the
 * inclusive display only where checkout charges it: taxable products, and domains while `orders.domains_taxable` is
 * on. Both are read through Settings until the TaxDisplay contract (spec K6) exists.
 *
 * @phpstan-type Plan array{id: int, name: string, tagline: string|null, features: list<string>, featured: bool, taxable: bool, in_stock: bool, url: string, pricing: list<array{cycle: string, recurring: int, setup: int, monthly: int|null}>}
 * @phpstan-type Group array{id: int, name: string, slug: string, tagline: string|null, url: string, taxable: bool, from: array{amount: int, cycle: string, per_month: bool, setup: int}|null, plans: list<Plan>}
 */
class Catalogue
{
    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36];

    public function __construct(private Settings $settings) {}

    /**
     * Visible groups with orderable products, in Catalog order.
     *
     * @param  array{multiplier: int}  $display
     * @return list<Group>
     */
    public function groups(string $currency, array $display): array
    {
        if (! app()->bound(PriceBook::class)) {
            return [];
        }

        $breakdown = (bool) $this->settings->get('catalog.show_monthly_breakdown', false);
        $groups = [];

        foreach (app(PriceBook::class)->storefront($currency) as $group) {
            $plans = array_map(fn (array $product): array => $this->plan($product, $display, $breakdown), $group['products']);

            $groups[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'slug' => $group['slug'],
                'tagline' => $this->text($group['tagline'] ?? null) ?? $this->text($group['headline'] ?? null) ?? $this->text($group['description']),
                'url' => Route::has('store.index') ? route('store.index').'#'.$group['slug'] : '#',
                'taxable' => in_array(true, array_column($plans, 'taxable'), true),
                'from' => $this->from($plans),
                'plans' => $plans,
            ];
        }

        return $groups;
    }

    /**
     * TLDs sold in the currency (guest prices), converted to the display.
     *
     * @param  array{multiplier: int}  $display
     * @return list<array{tld: string, featured: bool, badge: string|null, register: int|null, renew: int|null, transfer: int|null}>
     */
    public function domains(string $currency, array $display): array
    {
        if (! app()->bound(DomainPricing::class)) {
            return [];
        }

        $taxed = $this->domainsTaxed();
        $apply = fn (?int $amount): ?int => $amount === null || ! $taxed ? $amount : PriceDisplay::apply($amount, $display);

        return array_map(fn (array $row): array => [
            ...$row,
            'register' => $apply($row['register']),
            'renew' => $apply($row['renew']),
            'transfer' => $apply($row['transfer']),
        ], app(DomainPricing::class)->catalog($currency));
    }

    /**
     * @param  array{id: int, name: string, description: ?string, tagline?: ?string, is_featured?: bool, is_taxable?: bool, in_stock: bool, pricing: list<array{cycle: string, recurring: int, setup: int}>}  $product
     * @param  array{multiplier: int}  $display
     * @return Plan
     */
    private function plan(array $product, array $display, bool $breakdown): array
    {
        $taxable = (bool) ($product['is_taxable'] ?? true);
        $apply = fn (int $amount): int => $taxable ? PriceDisplay::apply($amount, $display) : $amount;
        $description = $this->text($product['description'] ?? null);
        $tagline = $this->text($product['tagline'] ?? null);
        $features = $description !== null && mb_strlen($description) <= 240 && ! str_contains($description, '#')
            ? array_slice(array_values(array_filter(array_map(fn (string $feature): string => mb_ucfirst(trim($feature)), preg_split('/\s*(?:,|·|•|\R)\s*/u', $description) ?: []))), 0, 6)
            : [];

        return [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'tagline' => $tagline === $description ? null : $tagline,
            'features' => $features,
            'featured' => (bool) ($product['is_featured'] ?? false),
            'taxable' => $taxable,
            'in_stock' => (bool) $product['in_stock'],
            'url' => Route::has('store.products.show') ? route('store.products.show', $product['id']) : '#',
            'pricing' => array_map(function (array $row) use ($apply, $breakdown): array {
                $recurring = $apply((int) $row['recurring']);
                $months = $breakdown ? (self::MONTHS[$row['cycle']] ?? null) : null;

                return [
                    'cycle' => (string) $row['cycle'],
                    'recurring' => $recurring,
                    'setup' => $apply((int) $row['setup']),
                    'monthly' => $months === null ? null : intdiv($recurring + intdiv($months, 2), $months),
                ];
            }, $product['pricing']),
        ];
    }

    /**
     * The group's lowest price: per month over recurring cycles with the monthly breakdown on, else the lowest
     * amount a visitor can pay for any cycle.
     *
     * @param  list<Plan>  $plans
     * @return array{amount: int, cycle: string, per_month: bool, setup: int}|null
     */
    private function from(array $plans): ?array
    {
        $best = null;

        foreach ($plans as $plan) {
            foreach ($plan['pricing'] as $row) {
                $candidate = $row['monthly'] !== null
                    ? ['amount' => $row['monthly'], 'cycle' => $row['cycle'], 'per_month' => true, 'setup' => $row['setup']]
                    : ['amount' => $row['recurring'], 'cycle' => $row['cycle'], 'per_month' => false, 'setup' => $row['setup']];

                if ($best === null || ($candidate['per_month'] && ! $best['per_month']) || ($candidate['per_month'] === $best['per_month'] && $candidate['amount'] < $best['amount'])) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * Whether checkout taxes domain lines (Orders setting), so the domain prices carry the tax statement.
     */
    public function domainsTaxed(): bool
    {
        return (bool) $this->settings->get('orders.domains_taxable', true);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
