<?php

namespace App\Modules\Domains\Services;

use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Drivers\Registrars\Data\AvailabilityResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Support\DomainName;
use App\Modules\Domains\Support\SettingList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * Availability lookup and suggestions with prices in the visitor's currency and client group (spec §6).
 */
class DomainLookup
{
    public const MAX_SPOTLIGHT = 8;

    public function __construct(
        private DomainPricingService $pricing,
        private RegistrarManager $registrars,
        private RegistrarGateway $gateway,
        private Settings $settings,
    ) {}

    /**
     * @return array{query: string, normalised: string|null, error: string|null, currency: string, results: list<array<string, mixed>>, suggestions: list<array<string, mixed>>}
     */
    public function search(string $query, string $currency, ?int $clientGroupId = null): array
    {
        $response = ['query' => $query, 'normalised' => null, 'error' => null, 'currency' => $currency, 'results' => [], 'suggestions' => []];

        try {
            $ascii = DomainName::toAscii($query, (bool) $this->settings->get('domains.allow_idn', true));
        } catch (InvalidArgumentException $exception) {
            return [...$response, 'error' => $exception->getMessage()];
        }

        $response['normalised'] = $ascii;

        if (str_contains($ascii, '.')) {
            $parts = $this->pricing->split($ascii);

            if ($parts === null) {
                $response['results'][] = ['domain' => $ascii, 'domain_unicode' => DomainName::toUnicode($ascii), 'status' => 'unsupported', 'sellable' => false];

                return $response;
            }

            [$sld, $tlds] = [$parts[0], [$parts[1]]];
        } else {
            [$sld, $tlds] = [$ascii, $this->defaultTlds()];
        }

        $availability = $this->availability($sld, $tlds);
        $response['results'] = array_map(fn (AvailabilityResult $result): array => $this->present($result, $currency, $clientGroupId), $availability);
        $response['suggestions'] = $this->suggestions($sld, $tlds, $currency, $clientGroupId);

        return $response;
    }

    /**
     * Spotlight TLDs (max 8) followed by domains.lookup_tlds, active only.
     *
     * @return list<string>
     */
    private function defaultTlds(): array
    {
        $spotlight = Tld::query()->active()->where('is_spotlight', true)->ordered()->limit(self::MAX_SPOTLIGHT)->pluck('extension')->all();
        $configured = array_map(fn ($tld): string => Tld::normaliseExtension((string) $tld), SettingList::of($this->settings->get('domains.lookup_tlds', [])));

        return array_values(array_filter(array_unique([...$spotlight, ...$configured]), fn (string $tld): bool => $this->pricing->tld($tld) !== null));
    }

    /**
     * @param  list<string>  $tlds
     * @return list<AvailabilityResult>
     */
    private function availability(string $sld, array $tlds): array
    {
        if ($tlds === []) {
            return [];
        }

        $registrar = (string) $this->settings->get('domains.lookup_registrar', 'log');

        if (! $this->registrars->has($registrar) || ! in_array('availability', $this->registrars->capabilitiesOf($registrar), true)) {
            return array_map(fn (string $tld): AvailabilityResult => new AvailabilityResult($sld.$tld, 'unknown'), $tlds);
        }

        $key = 'domains.lookup.'.$registrar.'.'.$sld.'.'.md5(implode(',', $tlds));
        $ttl = (int) $this->settings->get('domains.lookup_cache_seconds', 300);

        try {
            /** @var list<AvailabilityResult> $results */
            $results = Cache::remember($key, $ttl, fn (): array => $this->gateway->call(
                $registrar,
                'availability',
                fn (Registrar $driver): array => $driver->checkAvailability($sld, $tlds),
                request: ['sld' => $sld, 'tlds' => $tlds],
            ));
        } catch (RegistrarException) {
            return array_map(fn (string $tld): AvailabilityResult => new AvailabilityResult($sld.$tld, 'unknown'), $tlds);
        }

        return $this->markInSystem($results);
    }

    /**
     * @param  list<string>  $checked
     * @return list<array<string, mixed>>
     */
    private function suggestions(string $sld, array $checked, string $currency, ?int $clientGroupId): array
    {
        $limit = (int) $this->settings->get('domains.suggestion_limit', 10);
        $registrar = (string) $this->settings->get('domains.lookup_registrar', 'log');
        $tlds = array_values(array_filter(
            array_map(fn ($tld): string => Tld::normaliseExtension((string) $tld), SettingList::of($this->settings->get('domains.suggestion_tlds', []))),
            fn (string $tld): bool => $this->pricing->tld($tld) !== null,
        ));

        if ($limit <= 0 || $tlds === [] || ! $this->registrars->has($registrar)) {
            return [];
        }

        $capabilities = $this->registrars->capabilitiesOf($registrar);

        try {
            if (in_array('suggestions', $capabilities, true)) {
                $results = Cache::remember('domains.suggest.'.$registrar.'.'.$sld.'.'.md5(implode(',', $tlds)), (int) $this->settings->get('domains.lookup_cache_seconds', 300), fn (): array => $this->gateway->call(
                    $registrar,
                    'suggestions',
                    fn (Registrar $driver): array => $driver->suggest($sld, $tlds, $limit + count($checked)),
                    request: ['term' => $sld, 'tlds' => $tlds],
                ));
            } else {
                $results = $this->availability($sld, array_values(array_diff($tlds, $checked)));
            }
        } catch (RegistrarException) {
            return [];
        }

        $exact = array_map(fn (string $tld): string => $sld.$tld, $checked);
        $suggestions = [];

        foreach ($this->markInSystem($results) as $result) {
            if (! $result->isAvailable() || in_array($result->domain, $exact, true)) {
                continue;
            }

            $presented = $this->present($result, $currency, $clientGroupId);

            if ($presented['sellable']) {
                $suggestions[] = $presented;
            }

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Names held by a non-terminal domain here are reported as registered (and not transferable).
     *
     * @param  list<AvailabilityResult>  $results
     * @return list<AvailabilityResult>
     */
    private function markInSystem(array $results): array
    {
        $held = Domain::query()->whereIn('active_name', array_map(fn (AvailabilityResult $result): string => $result->domain, $results))->pluck('active_name')->all();

        return array_map(fn (AvailabilityResult $result): AvailabilityResult => in_array($result->domain, $held, true)
            ? new AvailabilityResult($result->domain, 'in_system')
            : $result, $results);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AvailabilityResult $result, string $currency, ?int $clientGroupId): array
    {
        $parts = $this->pricing->split($result->domain);
        $tld = $parts === null ? null : $this->pricing->tld($parts[1]);
        $extension = $parts[1] ?? '';

        $registerTerms = $tld === null ? [] : array_map(fn (int $years): array => [
            'years' => $years,
            'price' => (int) $this->pricing->price($extension, 'register', $years, $currency, $clientGroupId),
        ], $this->pricing->terms($extension, 'register', $currency, $clientGroupId));

        $transferTerm = $tld === null ? [] : $this->pricing->terms($extension, 'transfer', $currency, $clientGroupId);
        $transferPrice = $transferTerm === [] ? null : $this->pricing->price($extension, 'transfer', $transferTerm[0], $currency, $clientGroupId);
        $available = $result->isAvailable();
        $status = $result->status === 'in_system' ? 'registered' : $result->status;

        return [
            'domain' => $result->domain,
            'domain_unicode' => DomainName::toUnicode($result->domain),
            'tld' => $extension,
            'status' => $status,
            'in_system' => $result->status === 'in_system',
            'is_premium' => $result->isPremium,
            'badge' => $tld?->badge,
            'sellable' => $registerTerms !== [],
            'register' => $registerTerms,
            'renew' => $this->pricing->price($extension, 'renew', 1, $currency, $clientGroupId),
            'transfer' => $transferPrice,
            'transferable' => $status === 'registered' && $result->status !== 'in_system' && $transferPrice !== null,
            'epp_required' => $tld->epp_required ?? true,
            'order_url' => $this->orderUrl($result->domain, $available ? 'register' : 'transfer', $available || $transferPrice !== null),
        ];
    }

    private function orderUrl(string $domain, string $action, bool $orderable): ?string
    {
        $route = (string) $this->settings->get('domains.order_route', '');

        return $orderable && $route !== '' && Route::has($route) ? route($route, ['domain' => $domain, 'action' => $action]) : null;
    }
}
