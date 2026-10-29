<?php

namespace App\Support\Search;

use App\Models\User;
use Closure;

/**
 * Admin global search. Modules register providers in bootModule():
 *
 *   app(GlobalSearch::class)->provider('Invoices', fn (string $term) => Invoice::query()->whereLike('number', "%{$term}%")
 *       ->limit(5)->get()->map(fn ($invoice) => ['title' => $invoice->number, 'subtitle' => ..., 'href' => route(...)])->all(),
 *       'billing.invoices.view');
 *
 * @api
 */
class GlobalSearch
{
    /** @var list<array{group: string, resolver: Closure(string): list<array{title: string, subtitle?: string, href: string}>, permission: ?string}> */
    private array $providers = [];

    /**
     * @param  Closure(string): list<array{title: string, subtitle?: string, href: string}>  $resolver
     */
    public function provider(string $group, Closure $resolver, ?string $permission = null): void
    {
        $this->providers[] = ['group' => $group, 'resolver' => $resolver, 'permission' => $permission];
    }

    /**
     * @return list<array{group: string, results: list<array{title: string, subtitle?: string, href: string}>}>
     */
    public function search(string $term, ?User $user): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            if ($provider['permission'] !== null && ! ($user?->hasPermission($provider['permission']) ?? false)) {
                continue;
            }

            $matches = ($provider['resolver'])($term);

            if ($matches !== []) {
                $results[] = ['group' => $provider['group'], 'results' => $matches];
            }
        }

        return $results;
    }
}
