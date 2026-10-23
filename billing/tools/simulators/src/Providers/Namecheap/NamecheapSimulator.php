<?php

namespace Mohar\Simulators\Providers\Namecheap;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * Namecheap's XML API for the registrar driver (ADR 0016 §2), written from the public API reference in our own words:
 *
 * - one URL (`/xml.response`), the operation named by `Command=`, GET or POST, and `ApiUser`, `ApiKey`, `UserName`,
 *   `ClientIp` on every call;
 * - HTTP 200 with `<ApiResponse Status="ERROR">` and numbered errors, including the allow-list refusal while `ClientIp`
 *   is not the whitelisted address;
 * - availability (with premium names and an unavailable registry), register (real-time or non-real-time), transfer
 *   with an EPP code and a status to poll, renew, reactivate, name servers, registrar lock, privacy, contacts, the
 *   domain list and info, and the extension list;
 * - async: `slow-` registrations stay out of the account until `settle_order`; transfers wait until `settle_transfer`.
 *
 * The API host is this one TLS port. Rate limits and a timeout after commit use the framework's faults (`rate_limit`,
 * `commit_then_hang`) on the command's operation (`domains_create`, …). The key is for local scenarios only.
 */
final class NamecheapSimulator implements HasControlActions, Simulator
{
    public const USER_NAME = 'simreseller';

    public const API_KEY = 'nc-sim-api-key-LocalScenario00000000';

    /** The address the account's allow-list holds; `ClientIp` must be this. */
    public const CLIENT_IP = '203.0.113.10';

    /** The account's starting balance, in US cents. */
    public const BALANCE = 50_000;

    /** A name held at another registrar from the start, with its EPP code (transfer scenarios). */
    public const FOREIGN_DOMAIN = 'taken-elsewhere.com';

    public const FOREIGN_EPP_CODE = 'Xfer-9f3k-Q7';

    public const DEFAULT_NAMESERVERS = ['dns1.registrar-servers.com', 'dns2.registrar-servers.com'];

    /** Commands that change something: a `next_error` applies to these only. */
    private const CHANGES = [
        'namecheap.domains.create', 'namecheap.domains.transfer.create', 'namecheap.domains.renew', 'namecheap.domains.reactivate',
        'namecheap.domains.dns.setcustom', 'namecheap.domains.setregistrarlock', 'namecheap.whoisguard.enable', 'namecheap.whoisguard.disable', 'namecheap.domains.setcontacts',
    ];

    public function key(): string
    {
        return 'namecheap';
    }

    public function reference(): array
    {
        return ['url' => 'https://www.namecheap.com/support/api/intro/', 'version' => 'XML API (domains, transfers, DNS, privacy)'];
    }

    public function routes(): array
    {
        $command = fn (string $name, \Closure $handler): Route => new Route(
            '*',
            '/xml.response',
            str_replace('.', '_', strtolower(substr($name, strlen('namecheap.')))),
            function (Request $request, Scenario $scenario) use ($name, $handler): Response {
                if ($refused = $this->refused($scenario, $request->input())) {
                    return $refused;
                }

                $api = new NamecheapApi($scenario);

                if (in_array(strtolower($name), self::CHANGES, true) && is_array($error = $scenario->state()->get('config', 'next_error'))) {
                    $scenario->state()->delete('config', 'next_error');
                    $answer = ($error['commit'] ?? false) ? $handler($api, $request) : null;

                    return $answer !== null && $this->failed($answer) ? $answer : NamecheapApi::error($name, (int) $error['number'], (string) $error['message']);
                }

                return $handler($api, $request);
            },
            fn (Request $request): bool => strcasecmp((string) ($request->input()['Command'] ?? ''), $name) === 0,
        );

        return [
            $command('namecheap.domains.check', fn (NamecheapApi $api, Request $request) => $api->check($request)),
            $command('namecheap.domains.create', fn (NamecheapApi $api, Request $request) => $api->create($request)),
            $command('namecheap.domains.transfer.create', fn (NamecheapApi $api, Request $request) => $api->transferCreate($request)),
            $command('namecheap.domains.transfer.getStatus', fn (NamecheapApi $api, Request $request) => $api->transferGetStatus($request)),
            $command('namecheap.domains.transfer.getList', fn (NamecheapApi $api, Request $request) => $api->transferGetList($request)),
            $command('namecheap.domains.renew', fn (NamecheapApi $api, Request $request) => $api->renew($request)),
            $command('namecheap.domains.reactivate', fn (NamecheapApi $api, Request $request) => $api->reactivate($request)),
            $command('namecheap.domains.getInfo', fn (NamecheapApi $api, Request $request) => $api->getInfo($request)),
            $command('namecheap.domains.getList', fn (NamecheapApi $api, Request $request) => $api->getList($request)),
            $command('namecheap.domains.getTldList', fn (NamecheapApi $api, Request $request) => $api->getTldList($request)),
            $command('namecheap.domains.dns.setCustom', fn (NamecheapApi $api, Request $request) => $api->setCustom($request)),
            $command('namecheap.domains.dns.getList', fn (NamecheapApi $api, Request $request) => $api->dnsGetList($request)),
            $command('namecheap.domains.getRegistrarLock', fn (NamecheapApi $api, Request $request) => $api->getRegistrarLock($request)),
            $command('namecheap.domains.setRegistrarLock', fn (NamecheapApi $api, Request $request) => $api->setRegistrarLock($request)),
            $command('namecheap.whoisguard.enable', fn (NamecheapApi $api, Request $request) => $api->privacy($request, true)),
            $command('namecheap.whoisguard.disable', fn (NamecheapApi $api, Request $request) => $api->privacy($request, false)),
            $command('namecheap.domains.getContacts', fn (NamecheapApi $api, Request $request) => $api->getContacts($request)),
            $command('namecheap.domains.setContacts', fn (NamecheapApi $api, Request $request) => $api->setContacts($request)),
            new Route('*', '/xml.response', 'unknown_command', function (Request $request, Scenario $scenario): Response {
                $input = $request->input();

                return $this->refused($scenario, $input) ?? NamecheapApi::error((string) ($input['Command'] ?? ''), NamecheapApi::E_UNKNOWN_COMMAND, 'The command is not supported.');
            }),
        ];
    }

    /**
     * The global checks every call passes first: the parameters, the credentials, the user name, and the allow-list.
     *
     * @param  array<array-key, mixed>  $input
     */
    private function refused(Scenario $scenario, array $input): ?Response
    {
        $command = (string) ($input['Command'] ?? '');

        foreach (['ApiUser' => NamecheapApi::E_MISSING_API_USER, 'ApiKey' => NamecheapApi::E_MISSING_API_KEY, 'UserName' => NamecheapApi::E_MISSING_USER_NAME, 'Command' => NamecheapApi::E_MISSING_COMMAND, 'ClientIp' => NamecheapApi::E_MISSING_CLIENT_IP] as $name => $number) {
            if (trim((string) ($input[$name] ?? '')) === '') {
                return NamecheapApi::error($command, $number, 'Parameter '.$name.' is missing.');
            }
        }

        if ((string) $input['ApiUser'] !== self::USER_NAME || ! hash_equals(self::API_KEY, (string) $input['ApiKey'])) {
            return NamecheapApi::error($command, NamecheapApi::E_BAD_KEY, 'The API key is not valid or API access is not enabled for this account.');
        }

        if ((string) $input['UserName'] !== self::USER_NAME) {
            return NamecheapApi::error($command, NamecheapApi::E_BAD_USER_NAME, 'The UserName is not valid for this account.');
        }

        if ($scenario->state()->get('config', 'ip_allowed', true) !== true || (string) $input['ClientIp'] !== self::CLIENT_IP) {
            return NamecheapApi::error($command, NamecheapApi::E_IP_NOT_ALLOWED, 'The address '.$input['ClientIp'].' is not on the allow-list of this account.');
        }

        return null;
    }

    private function failed(Response $response): bool
    {
        return str_contains($response->body, 'Status="ERROR"');
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
        $state->put('foreign', self::FOREIGN_DOMAIN, ['epp' => self::FOREIGN_EPP_CODE, 'expires' => time() + 200 * 86_400]);
        $state->put('foreign', 'example.com', ['epp' => 'not-for-transfer', 'expires' => time() + 900 * 86_400]);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        return [
            'env' => [
                'INTEGRATION_ENDPOINTS' => (string) json_encode(['namecheap.api' => $scenario->apiUrl().'/xml.response'], JSON_UNESCAPED_SLASHES),
                'OUTBOUND_ALLOW_LOOPBACK' => 'true',
            ],
            'notes' => [
                'Namecheap registrar: sandbox on, API user and user name '.self::USER_NAME.', API key '.self::API_KEY.', client IP '.self::CLIENT_IP.'.',
                'The Namecheap registrar driver reads the namecheap.api endpoint key (the full xml.response URL).',
            ],
        ];
    }

    /**
     * - `settle_order` {domain, outcome: active|failed}: the registry answers a `slow-` registration (a failure refunds it);
     * - `settle_transfer` {domain, outcome: complete|cancel}: the transfer ends (cancel refunds it);
     * - `set_transfer_status` {domain, status_id}: the transfer moves to any status id;
     * - `expire` {domain, days_ago}: the name's expiry is that many days in the past;
     * - `add_foreign_domain` {domain, epp_code, expires_in_days?}: a name held at another registrar;
     * - `set_balance` {cents}: the account balance (0 for the insufficient-funds case);
     * - `registry_down` {tld, down}: availability answers an error for that extension;
     * - `ip_allowed` {allowed}: false answers every call as before the allow-list applies;
     * - `next_error` {number, message, commit?}: the next change answers that error; with `commit` it still runs
     *   (an unknown outcome such as 3011511 after the work is done);
     * - `reset`: wipe the account, its id counters and the fault plan, and reseed it.
     */
    public function actions(): array
    {
        $domain = fn (array $input): string => is_string($input['domain'] ?? null) && $input['domain'] !== ''
            ? strtolower($input['domain'])
            : throw new \InvalidArgumentException('Give the domain name as `domain`.');

        return [
            'settle_order' => fn (array $input, Scenario $scenario): array => ['status' => (new NamecheapApi($scenario))->settleOrder($domain($input), (string) ($input['outcome'] ?? 'active'))],
            'settle_transfer' => fn (array $input, Scenario $scenario): array => ['status' => (new NamecheapApi($scenario))->settleTransfer($domain($input), (string) ($input['outcome'] ?? 'complete'))],
            'set_transfer_status' => fn (array $input, Scenario $scenario): array => ['status_id' => (new NamecheapApi($scenario))->setTransferStatus($domain($input), (int) $input['status_id'])],
            'expire' => fn (array $input, Scenario $scenario): array => ['days_ago' => (new NamecheapApi($scenario))->expire($domain($input), (int) ($input['days_ago'] ?? 1))],
            'add_foreign_domain' => function (array $input, Scenario $scenario) use ($domain): array {
                $scenario->state()->put('foreign', $domain($input), ['epp' => (string) ($input['epp_code'] ?? ''), 'expires' => time() + (int) ($input['expires_in_days'] ?? 200) * 86_400]);

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
            'next_error' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'next_error', ['number' => (int) $input['number'], 'message' => (string) ($input['message'] ?? 'Unknown error from the provider.'), 'commit' => (bool) ($input['commit'] ?? false)]);

                return ['next_error' => (int) $input['number']];
            },
            'reset' => function (array $input, Scenario $scenario): array {
                $scenario->state()->transaction(function () use ($scenario): void {
                    foreach (['domains', 'transfers', 'foreign', 'ids', 'calls', 'config'] as $namespace) {
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
