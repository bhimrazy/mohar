<?php

namespace App\Support\Integrations;

use App\Data\Integrations\Endpoint;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * Where a driver sends requests to a provider with a fixed host (Stripe, Khalti, eSewa, ResellerClub, ...), with the
 * HTTP options that destination needs, in one call (ADR 0016 §3). Drivers always ask, so there is no simulator branch
 * in driver code:
 *
 *   $endpoint = Endpoints::for('stripe.api', config('payments_stripe.api'));
 *   Http::withOptions($endpoint->options)->post($endpoint->url('v1/checkout/sessions'), …);
 *
 * - No override: the base URL is the default and the options are [], so behaviour is exactly as before.
 * - An override, honoured only when the app runs as `local` or `testing` (every other environment, including an empty
 *   or unknown one, fails closed): from the environment only, INTEGRATION_ENDPOINTS as a JSON object of key =>
 *   https base URL, and INTEGRATION_CA_BUNDLE. The options keep TLS verification on against the scenario's CA bundle
 *   and pin the connection to the address OutboundAddressGuard checked, so loopback is reachable only where the guard
 *   allows it.
 * - Overrides that are not https, carry credentials, a query or a fragment, or have a malformed key are ignored and
 *   logged once (keys only). In a non-development environment every override is ignored and logged once as critical.
 *
 * Staff can never set an override: there is no database setting behind it.
 *
 * @api
 */
final class Endpoints
{
    /** The only environments where overrides (and loopback, OutboundAddressGuard) are honoured. */
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    private const KEY_PATTERN = '/^[a-z0-9]+(\.[a-z0-9_-]+)+$/';

    private bool $reported = false;

    public function __construct(
        private readonly Application $app,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @throws OutboundAddressRefused when an override points at an address the install policy refuses
     */
    public static function for(string $key, string $default): Endpoint
    {
        return app(self::class)->endpoint($key, $default);
    }

    /**
     * Whether the app runs where simulators may be used. Anything else, including an empty or unknown environment
     * name, is treated as production.
     */
    public static function developmentEnvironment(Application $app): bool
    {
        return $app->environment(self::DEVELOPMENT_ENVIRONMENTS);
    }

    /**
     * @throws OutboundAddressRefused
     */
    public function endpoint(string $key, string $default): Endpoint
    {
        $this->report();
        $override = self::developmentEnvironment($this->app) ? ($this->parsed()['valid'][$key] ?? null) : null;

        if ($override === null) {
            return new Endpoint($default);
        }

        // Shaped like the default, so the driver's paths join the same way with and without an override.
        $override = str_ends_with($default, '/') ? $override.'/' : $override;

        $port = (int) (parse_url($override, PHP_URL_PORT) ?? 443);
        // The guard is resolved per call, so the policy and resolver in force at the time of the request apply.
        $guard = $this->app->make(OutboundAddressGuard::class);
        $bundle = config('integrations.endpoints.ca_bundle');

        return new Endpoint($override, [
            ...$guard->pinOptions($guard->resolve((string) parse_url($override, PHP_URL_HOST), $port)),
            // Verification stays on; a scenario's own CA replaces the system store for this destination only.
            'verify' => is_string($bundle) && $bundle !== '' ? $bundle : true,
        ]);
    }

    /**
     * The keys with an override in effect (none outside the development environments).
     *
     * @return list<string>
     */
    public function activeKeys(): array
    {
        return self::developmentEnvironment($this->app) ? array_keys($this->parsed()['valid']) : [];
    }

    /**
     * Every key set in INTEGRATION_ENDPOINTS, usable or not, in any environment: what the install checks report when the
     * app is not a development environment.
     *
     * @return list<string>
     */
    public function configuredKeys(): array
    {
        ['valid' => $valid, 'ignored' => $ignored] = $this->parsed();

        return [...array_keys($valid), ...$ignored];
    }

    /**
     * Log the overrides once per boot, keys only, never URLs: a warning for active ones in development and tests,
     * critical for any set elsewhere (they are ignored there), and a warning for each malformed one.
     */
    public function report(): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;
        ['valid' => $valid, 'ignored' => $ignored] = $this->parsed();
        $all = [...array_keys($valid), ...$ignored];

        if ($all === []) {
            return;
        }

        if (! self::developmentEnvironment($this->app)) {
            $this->log->critical('Integration endpoint overrides are set outside a development environment and were ignored.', ['keys' => $all]);

            return;
        }

        if ($ignored !== []) {
            $this->log->warning('Malformed integration endpoint overrides were ignored: use https base URLs without credentials, query or fragment.', ['keys' => $ignored]);
        }

        if ($valid !== []) {
            $this->log->warning('Integration endpoint overrides are active.', ['keys' => array_keys($valid)]);
        }
    }

    /**
     * INTEGRATION_ENDPOINTS read as key => base URL (without a trailing slash), split into usable overrides and the
     * keys of ignored ones ("(not a JSON object)" when the whole value is unreadable). A key that does not match
     * KEY_PATTERN is never repeated, since it may be a pasted URL with credentials: such keys are only counted
     * ("(2 malformed keys)"), so logs, the installer and the dashboard show nothing an operator did not mean as a key.
     *
     * @return array{valid: array<string, string>, ignored: list<string>}
     */
    private function parsed(): array
    {
        $raw = config('integrations.endpoints.overrides');

        if ($raw === null || $raw === '' || $raw === []) {
            return ['valid' => [], 'ignored' => []];
        }

        $overrides = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($overrides) || array_is_list($overrides)) {
            return ['valid' => [], 'ignored' => ['(not a JSON object)']];
        }

        $valid = [];
        $ignored = [];
        $malformed = 0;

        foreach ($overrides as $key => $url) {
            $parts = is_string($url) ? parse_url($url) : false;
            $usable = is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1
                && is_array($parts)
                && ($parts['scheme'] ?? null) === 'https'
                && ($parts['host'] ?? '') !== ''
                && ! isset($parts['user'])
                && ! isset($parts['pass'])
                && ! isset($parts['query'])
                && ! isset($parts['fragment'])
                && preg_match('/[\s\\\\]/', (string) $url) !== 1;

            if ($usable) {
                $valid[$key] = rtrim((string) $url, '/');
            } elseif (is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1) {
                $ignored[] = $key;
            } else {
                $malformed++;
            }
        }

        if ($malformed > 0) {
            $ignored[] = "({$malformed} malformed ".($malformed === 1 ? 'key' : 'keys').')';
        }

        return ['valid' => $valid, 'ignored' => $ignored];
    }
}
