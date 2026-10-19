<?php

namespace App\Modules\Storefront\Services;

use App\Contracts\Catalog\PriceBook;
use App\Contracts\Domains\DomainPricing;
use Illuminate\Support\Facades\Route;

/**
 * Live Catalog groups and Domains prices in one currency, shaped for the public site and converted to the price
 * display (with or without tax). Nothing here is typed by the host: every amount comes from PriceBook or
 * DomainPricing at render time (cached by the caller).
 *
 * @phpstan-type Plan array{id: int, name: string, tagline: string|null, features: list<string>, featured: bool, in_stock: bool, url: string, pricing: list<array{cycle: string, recurring: int, setup: int, monthly: int|null}>}
 * @phpstan-type Group array{id: int, name: string, slug: string, tagline: string|null, url: string, from: array{amount: int, cycle: string, per_month: bool}|null, plans: list<Plan>}
 */
class Catalogue
{
    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36];

    public function __construct(private PriceBook $priceBook) {}

    /**
     * Visible groups with orderable products, in Catalog order.
     *
     * @param  array{multiplier: int}  $display
     * @return list<Group>
     */
    public function groups(string $currency, array $display): array
    {
        $groups = [];

        foreach ($this->priceBook->storefront($currency) as $group) {
            $plans = array_map(fn (array $product): array => $this->plan($product, $display), $group['products']);

            $groups[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'slug' => $group['slug'],
                'tagline' => $this->text($group['tagline'] ?? null) ?? $this->text($group['headline'] ?? null) ?? $this->text($group['description']),
                'url' => Route::has('store.index') ? route('store.index').'#'.$group['slug'] : '#',
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

        $apply = fn (?int $amount): ?int => $amount === null ? null : PriceDisplay::apply($amount, $display);

        return array_map(fn (array $row): array => [
            ...$row,
            'register' => $apply($row['register']),
            'renew' => $apply($row['renew']),
            'transfer' => $apply($row['transfer']),
        ], app(DomainPricing::class)->catalog($currency));
    }

    /**
     * @param  array{id: int, name: string, description: ?string, tagline?: ?string, is_featured?: bool, in_stock: bool, pricing: list<array{cycle: string, recurring: int, setup: int}>}  $product
     * @param  array{multiplier: int}  $display
     * @return Plan
     */
    private function plan(array $product, array $display): array
    {
        $description = $this->text($product['description'] ?? null);
        $tagline = $this->text($product['tagline'] ?? null);
        $features = $description !== null && mb_strlen($description) <= 240 && ! str_contains($description, '#')
            ? array_slice(array_values(array_filter(array_map(trim(...), preg_split('/\s*(?:,|·|•|\R)\s*/u', $description) ?: []))), 0, 6)
            : [];

        return [
            'id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'tagline' => $tagline === $description ? null : $tagline,
            'features' => $features,
            'featured' => (bool) ($product['is_featured'] ?? false),
            'in_stock' => (bool) $product['in_stock'],
            'url' => Route::has('store.products.show') ? route('store.products.show', $product['id']) : '#',
            'pricing' => array_map(function (array $row) use ($display): array {
                $recurring = PriceDisplay::apply((int) $row['recurring'], $display);
                $months = self::MONTHS[$row['cycle']] ?? null;

                return [
                    'cycle' => (string) $row['cycle'],
                    'recurring' => $recurring,
                    'setup' => PriceDisplay::apply((int) $row['setup'], $display),
                    'monthly' => $months === null ? null : intdiv($recurring + intdiv($months, 2), $months),
                ];
            }, $product['pricing']),
        ];
    }

    /**
     * The group's lowest price: per month over recurring cycles, else the lowest one-time price.
     *
     * @param  list<Plan>  $plans
     * @return array{amount: int, cycle: string, per_month: bool}|null
     */
    private function from(array $plans): ?array
    {
        $best = null;

        foreach ($plans as $plan) {
            foreach ($plan['pricing'] as $row) {
                $candidate = $row['monthly'] !== null
                    ? ['amount' => $row['monthly'], 'cycle' => $row['cycle'], 'per_month' => true]
                    : ['amount' => $row['recurring'], 'cycle' => $row['cycle'], 'per_month' => false];

                if ($best === null || ($candidate['per_month'] && ! $best['per_month']) || ($candidate['per_month'] === $best['per_month'] && $candidate['amount'] < $best['amount'])) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
