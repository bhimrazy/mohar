<?php

namespace App\Support\Integrations;

use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * Where a driver sends requests to a provider with a fixed host (Stripe, Khalti, eSewa, ResellerClub, ...), and the
 * extra HTTP options that destination needs (ADR 0016 §3). Drivers always ask, so there is no simulator branch in
 * driver code:
 *
 *   Http::withOptions(Endpoints::options('stripe.api'))->post(Endpoints::url('stripe.api', config('payments_stripe.api')).'/v1/…');
 *
 * - No override: url() returns the default and options() returns [], so behaviour is exactly as before.
 * - An override (config `integrations.endpoints`, from the environment only: INTEGRATION_ENDPOINTS as a JSON map of
 *   key => base URL, INTEGRATION_CA_BUNDLE): url() returns it, and options() keeps TLS verification on against the
 *   scenario's CA bundle and pins the connection to the address OutboundAddressGuard checked, so loopback is reachable
 *   only where the install policy allows it (never in production).
 * - In production every override is ignored, and reported once as critical with the keys only.
 *
 * Staff can never set an override: there is no database setting behind it.
 *
 * @api
 */
final class Endpoints
{
    private bool $reported = false;

    public function __construct(
        private readonly Application $app,
        private readonly LoggerInterface $log,
    ) {}

    public static function url(string $key, string $default): string
    {
        return app(self::class)->urlFor($key, $default);
    }

    /**
     * @return array<string, mixed> Guzzle request options; [] without an override
     *
     * @throws OutboundAddressRefused when the override points at an address the install policy refuses
     */
    public static function options(string $key): array
    {
        return app(self::class)->optionsFor($key);
    }

    public function urlFor(string $key, string $default): string
    {
        return $this->override($key) ?? $default;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OutboundAddressRefused
     */
    public function optionsFor(string $key): array
    {
        $override = $this->override($key);

        if ($override === null) {
            return [];
        }

        $host = (string) parse_url($override, PHP_URL_HOST);
        $port = (int) (parse_url($override, PHP_URL_PORT) ?? (parse_url($override, PHP_URL_SCHEME) === 'http' ? 80 : 443));
        $bundle = config('integrations.endpoints.ca_bundle');

        // The guard is resolved per call, so the policy and resolver in force at the time of the request apply.
        $guard = $this->app->make(OutboundAddressGuard::class);

        return [
            ...$guard->pinOptions($guard->resolve($host, $port)),
            // Verification stays on; a scenario's own CA replaces the system store for this destination only.
            'verify' => is_string($bundle) && $bundle !== '' ? $bundle : true,
        ];
    }

    /**
     * The keys with an override in effect (none in production).
     *
     * @return list<string>
     */
    public function activeKeys(): array
    {
        return $this->app->isProduction() ? [] : array_keys($this->configured());
    }

    /**
     * Log the overrides once per boot: a warning in development and tests, critical in production, where they are
     * ignored. Keys only, never URLs.
     */
    public function report(): void
    {
        $keys = array_keys($this->configured());

        if ($this->reported || $keys === []) {
            return;
        }

        $this->reported = true;

        if ($this->app->isProduction()) {
            $this->log->critical('Integration endpoint overrides are set in production and were ignored.', ['keys' => $keys]);

            return;
        }

        $this->log->warning('Integration endpoint overrides are active.', ['keys' => $keys]);
    }

    private function override(string $key): ?string
    {
        if ($this->app->isProduction()) {
            $this->report();

            return null;
        }

        return $this->configured()[$key] ?? null;
    }

    /**
     * Configured overrides that are http(s) URLs with a host; anything else is ignored.
     *
     * @return array<string, string>
     */
    private function configured(): array
    {
        $overrides = config('integrations.endpoints.overrides', []);
        $valid = [];

        foreach (is_array($overrides) ? $overrides : [] as $key => $url) {
            if (is_string($key) && is_string($url) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '') {
                $valid[$key] = rtrim($url, '/');
            }
        }

        return $valid;
    }
}
