<?php

namespace Mohar\Simulators\Providers\ResellerClub;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * ResellerClub's HTTP API (LogicBoxes) for the registrar driver (ADR 0016 §2), written from the public HTTP API
 * reference in our own words:
 *
 * - `auth-userid` and `api-key` on every call, GET for reads and POST for changes, JSON by the `.json` suffix;
 * - refusals as HTTP 500 `{"status": "ERROR", "message": …}`, including "Access Denied" while the caller's address is
 *   not on the allow-list (`ip_allowed`);
 * - customers, contacts and domain orders; availability (with premium names and an unavailable registry), register,
 *   transfer with a later auth code, renew bound to the current expiry, restore, name servers, theft lock, privacy,
 *   contacts, order details (with the domain secret), order id and order search;
 * - async orders: `slow-` names and transfers stay InActive until `settle_order`.
 *
 * Both API hosts (httpapi.com and domaincheck.httpapi.com) are this one TLS port. Rate limits and a timeout after
 * commit use the framework's faults (`rate_limit`, `commit_then_hang`). The key is for local scenarios only.
 */
final class ResellerClubSimulator implements HasControlActions, Simulator
{
    public const RESELLER_ID = '600001';

    public const API_KEY = 'rc-sim-api-key-LocalScenario0000';

    /** The reseller's starting balance, in US cents. */
    public const BALANCE = 50_000;

    /** A name held at another registrar from the start, with its auth code (transfer scenarios). */
    public const FOREIGN_DOMAIN = 'taken-elsewhere.com';

    public const FOREIGN_AUTH_CODE = 'Xfer-9f3k-Q7';

    public function key(): string
    {
        return 'resellerclub';
    }

    public function reference(): array
    {
        return ['url' => 'https://manage.resellerclub.com/kb/answer/753', 'version' => 'HTTP API (domains, customers, contacts)'];
    }

    public function routes(): array
    {
        $api = fn (string $method, string $path, string $operation, \Closure $handler): Route => new Route('*', '/api/'.$path, $operation, function (Request $request, Scenario $scenario) use ($method, $handler): Response {
            if (str_starts_with(strtolower((string) $request->header('content-type')), 'multipart/')) {
                return ResellerClubApi::error('Send parameters as a query string or a URL-encoded form, not multipart.');
            }

            $input = $request->input();

            if ($scenario->state()->get('config', 'ip_allowed', true) !== true
                || (string) ($input['auth-userid'] ?? '') !== self::RESELLER_ID
                || ! hash_equals(self::API_KEY, (string) ($input['api-key'] ?? ''))) {
                return ResellerClubApi::error('Access Denied. You are not allowed to perform this action.');
            }

            if ($request->method !== $method) {
                return ResellerClubApi::error('This action must be sent with the '.$method.' method.');
            }

            return $handler(new ResellerClubApi($scenario), $request, $input);
        });

        return [
            $api('GET', 'domains/available.json', 'available', fn (ResellerClubApi $rc, Request $request) => $rc->available($request)),
            $api('POST', 'customers/v2/signup.json', 'customer_signup', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->signup($input)),
            $api('GET', 'customers/details.json', 'customer_details', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->customerDetails($input)),
            $api('POST', 'contacts/add.json', 'contact_add', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->addContact($input)),
            $api('GET', 'contacts/details.json', 'contact_details', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->contactDetails($input)),
            $api('POST', 'domains/register.json', 'register', fn (ResellerClubApi $rc, Request $request) => $rc->register($request)),
            $api('POST', 'domains/transfer.json', 'transfer', fn (ResellerClubApi $rc, Request $request) => $rc->transfer($request)),
            $api('POST', 'domains/transfer/submit-auth-code.json', 'submit_auth_code', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->submitAuthCode($input)),
            $api('GET', 'domains/validate-transfer.json', 'validate_transfer', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->validateTransfer($input)),
            $api('POST', 'domains/renew.json', 'renew', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->renew($input)),
            $api('POST', 'domains/restore.json', 'restore', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->restore($input)),
            $api('GET', 'domains/details-by-name.json', 'details_by_name', fn (ResellerClubApi $rc, Request $request) => $rc->details($request)),
            $api('GET', 'domains/orderid.json', 'orderid', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->orderId($input)),
            $api('GET', 'domains/search.json', 'search', fn (ResellerClubApi $rc, Request $request) => $rc->search($request)),
            $api('POST', 'domains/modify-ns.json', 'modify_ns', fn (ResellerClubApi $rc, Request $request) => $rc->modifyNameservers($request)),
            $api('POST', 'domains/enable-theft-protection.json', 'enable_theft_protection', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->theftProtection($input, true)),
            $api('POST', 'domains/disable-theft-protection.json', 'disable_theft_protection', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->theftProtection($input, false)),
            $api('GET', 'domains/locks.json', 'locks', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->locks($input)),
            $api('POST', 'domains/modify-privacy-protection.json', 'modify_privacy', fn (ResellerClubApi $rc, Request $request, array $input) => $rc->privacy($input)),
            $api('POST', 'domains/modify-contact.json', 'modify_contact', fn (ResellerClubApi $rc, Request $request) => $rc->modifyContact($request)),
        ];
    }

    public function operations(): array
    {
        return array_map(fn (Route $route): string => $route->operation, $this->routes());
    }

    public function seed(Scenario $scenario): void
    {
        $state = $scenario->state();
        $state->put('config', 'balance', self::BALANCE);
        $state->put('config', 'ip_allowed', true);
        $state->put('config', 'registry_down', []);
        $state->put('registry', self::FOREIGN_DOMAIN, ['auth_code' => self::FOREIGN_AUTH_CODE, 'endtime' => time() + 200 * 86_400]);
        $state->put('registry', 'example.com', ['auth_code' => 'not-for-transfer', 'endtime' => time() + 900 * 86_400]);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        $api = $scenario->apiUrl();

        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['resellerclub.api' => $api.'/api', 'resellerclub.check' => $api.'/api'], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => [
                'ResellerClub registrar: test mode on, demo reseller id '.self::RESELLER_ID.', demo API key '.self::API_KEY.'.',
                'The resellerclub.api and resellerclub.check endpoint keys are read by the ResellerClub driver, which arrives in the next PR (#280).',
            ],
        ];
    }

    /**
     * - `settle_order` {domain, outcome: active|failed}: the registry answers a `slow-` registration or a transfer
     *   that has its auth code;
     * - `set_status` {domain, status}: the order moves on as time would (Pending Delete Restorable, Deleted, …);
     * - `add_foreign_domain` {domain, auth_code, expires_in_days?}: a name held at another registrar;
     * - `set_balance` {cents}: the reseller balance (0 for the insufficient-funds case);
     * - `registry_down` {tld, down}: availability answers `unknown` for that extension;
     * - `ip_allowed` {allowed}: false answers every call "Access Denied", as before the allow-list applies;
     * - `next_actionstatus` {value}: the next change answers this actionstatus (an unknown one, say) and still runs;
     * - `reset`: wipe the account, its id counters and the fault plan, and reseed it, as the demo account is wiped daily.
     */
    public function actions(): array
    {
        $domain = fn (array $input): string => is_string($input['domain'] ?? null) && $input['domain'] !== ''
            ? strtolower($input['domain'])
            : throw new \InvalidArgumentException('Give the domain name as `domain`.');

        return [
            'settle_order' => fn (array $input, Scenario $scenario): array => ['status' => (new ResellerClubApi($scenario))->settle($domain($input), (string) ($input['outcome'] ?? 'active'))],
            'set_status' => fn (array $input, Scenario $scenario): array => ['status' => (new ResellerClubApi($scenario))->setStatus($domain($input), (string) ($input['status'] ?? ''))],
            'next_actionstatus' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'next_actionstatus', (string) ($input['value'] ?? ''));

                return ['next_actionstatus' => (string) ($input['value'] ?? '')];
            },
            'add_foreign_domain' => function (array $input, Scenario $scenario) use ($domain): array {
                $scenario->state()->put('registry', $domain($input), ['auth_code' => (string) ($input['auth_code'] ?? ''), 'endtime' => time() + (int) ($input['expires_in_days'] ?? 200) * 86_400]);

                return ['domain' => $domain($input)];
            },
            'set_balance' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'balance', (int) $input['cents']);

                return ['balance' => (int) $input['cents']];
            },
            'registry_down' => function (array $input, Scenario $scenario): array {
                $down = array_values(array_diff((array) $scenario->state()->get('config', 'registry_down', []), [(string) $input['tld']]));
                $scenario->state()->put('config', 'registry_down', ($input['down'] ?? true) ? [...$down, (string) $input['tld']] : $down);

                return ['registry_down' => $scenario->state()->get('config', 'registry_down')];
            },
            'ip_allowed' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'ip_allowed', (bool) $input['allowed']);

                return ['ip_allowed' => (bool) $input['allowed']];
            },
            'reset' => function (array $input, Scenario $scenario): array {
                $scenario->state()->transaction(function () use ($scenario): void {
                    foreach (['customers', 'contacts', 'orders', 'names', 'registry', 'ids', 'calls', 'config'] as $namespace) {
                        foreach (array_keys($scenario->state()->all($namespace)) as $key) {
                            $scenario->state()->delete($namespace, (string) $key);
                        }
                    }

                    $scenario->faults()->clear();
                    $this->seed($scenario);
                });

                return ['reset' => true];
            },
        ];
    }
}
