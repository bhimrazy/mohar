<?php

namespace Mohar\Simulators\Providers\Whm;

use Mohar\Simulators\Contracts\HasControlActions;
use Mohar\Simulators\Contracts\RecordsLive;
use Mohar\Simulators\Contracts\Simulator;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Recorder;
use Mohar\Simulators\Kernel\Scenario;

/**
 * A cPanel & WHM server for the cPanel driver (ADR 0016 §2): WHM API 1 for the 17 functions the driver calls, with a
 * root and a reseller login, token ACLs, package grants, username rules, kept DNS zones, and login links served on the
 * same TLS port. It holds no real data; the seeded tokens and passwords below are for local scenarios only.
 */
final class WhmSimulator implements HasControlActions, RecordsLive, Simulator
{
    public const ROOT_TOKEN = 'SIMROOT0TOKEN0FOR0LOCAL0SCENARIO';

    public const RESELLER_TOKEN = 'SIMRESELLER0TOKEN0LOCAL0SCENARIO';

    public const ROOT_PASSWORD = 'Sim-Root-Login-2026!';

    /** What a reseller may do: every ACL the driver needs, and no more. */
    public const RESELLER_ACLS = [
        'basic-whm-functions', 'list-accts', 'acct-summary', 'create-acct', 'suspend-acct', 'kill-acct',
        'upgrade-account', 'passwd', 'list-pkgs', 'show-bandwidth', 'ns-config', 'create-user-session',
    ];

    public function key(): string
    {
        return 'whm';
    }

    public function reference(): array
    {
        return ['url' => 'https://api.docs.cpanel.net/whm/introduction', 'version' => 'WHM API 1, cPanel & WHM 11.124'];
    }

    public function routes(): array
    {
        $routes = array_map(
            fn (string $function): Route => new Route('*', "/json-api/{$function}", $function, fn (Request $request, Scenario $scenario): Response => (new WhmApi($scenario))->handle($function, $request)),
            array_keys(WhmApi::FUNCTIONS),
        );

        return [
            ...$routes,
            new Route('*', '/json-api/{function}', 'unknown_function', fn (Request $request, Scenario $scenario, array $parameters): Response => (new WhmApi($scenario))->handle($parameters['function'], $request)),
            new Route('GET', '/{session}/login/', 'login', fn (Request $request, Scenario $scenario, array $parameters): Response => (new WhmApi($scenario))->login($parameters['session'], $request)),
        ];
    }

    public function operations(): array
    {
        return [...array_keys(WhmApi::FUNCTIONS), 'login'];
    }

    /**
     * A server with root and one reseller (reseller1, limit 3, granted root_basic), four packages, two accounts and
     * two free dedicated IPs. Documentation addresses (RFC 5737) and `.test` names only.
     */
    public function seed(Scenario $scenario): void
    {
        $state = $scenario->state();

        foreach ([
            'version' => '11.124.0.12',
            'hostname' => 'whm.sim.test',
            'nameservers' => ['ns1.sim.test', 'ns2.sim.test'],
            'licence_valid' => true,
            'shared_ip' => '203.0.113.20',
            'dedicated_ips' => ['203.0.113.50', '203.0.113.51'],
            'free_ips' => ['203.0.113.50', '203.0.113.51'],
            'themes' => ['jupiter'],
        ] as $key => $value) {
            $state->put('config', $key, $value);
        }

        $state->put('users', 'root', ['privileges' => ['all'], 'password_hash' => password_hash(self::ROOT_PASSWORD, PASSWORD_DEFAULT), 'reseller' => false]);
        $state->put('users', 'reseller1', ['privileges' => self::RESELLER_ACLS, 'password_hash' => password_hash(self::ROOT_PASSWORD, PASSWORD_DEFAULT), 'reseller' => true, 'limit' => 3, 'packages' => ['root_basic']]);
        self::issueToken($scenario, 'root', self::ROOT_TOKEN, ['all']);
        self::issueToken($scenario, 'reseller1', self::RESELLER_TOKEN, self::RESELLER_ACLS);

        foreach ([
            ['root_basic', '10240', '102400', '0', 'root'],
            ['root_pro', '51200', 'unlimited', '5', 'root'],
            ['reseller1_starter', '500', '5000', '0', 'reseller1'],
            ['default', 'unlimited', 'unlimited', 'unlimited', 'root'],
        ] as [$name, $quota, $bandwidth, $addons, $owner]) {
            $state->put('packages', $name, ['name' => $name, 'QUOTA' => $quota, 'BWLIMIT' => $bandwidth, 'MAXADDON' => $addons, 'owner' => $owner]);
        }

        self::addAccount($scenario, ['user' => 'alpha', 'domain' => 'alpha-books.test', 'plan' => 'root_basic', 'owner' => 'root', 'unix_startdate' => 1_700_000_000, 'diskused' => '120M']);
        self::addAccount($scenario, ['user' => 'bravo', 'domain' => 'bravo-cafe.test', 'plan' => 'reseller1_starter', 'owner' => 'reseller1', 'unix_startdate' => 1_760_000_000]);
        $state->put('bandwidth', 'alpha', 1_572_864);
    }

    public function appConfiguration(Scenario $scenario): array
    {
        return [
            'env' => ['OUTBOUND_ALLOW_LOOPBACK' => 'true'],
            'notes' => [
                'Add a cPanel server: hostname 127.0.0.1, port '.$scenario->apiPort().', username root, API token '.self::ROOT_TOKEN.' (or reseller1 with '.self::RESELLER_TOKEN.').',
                'Run Test connection, check the fingerprint against the scenario CA, then "Trust this certificate".',
                'Packages: root_basic, root_pro, default (root); reseller1_starter and root_basic (reseller1, limit 3 accounts).',
            ],
        ];
    }

    public function actions(): array
    {
        return [
            // Someone else's account, made outside the app (the verify sweep must never adopt it).
            'add_account' => fn (array $input, Scenario $scenario): array => ['account' => self::addAccount($scenario, $input)],
            'issue_token' => fn (array $input, Scenario $scenario): array => ['token' => self::issueToken($scenario, (string) $input['user'], (string) ($input['token'] ?? strtoupper(bin2hex(random_bytes(16)))), array_values((array) ($input['acls'] ?? ['all'])), isset($input['expires_at']) ? (int) $input['expires_at'] : null)],
            'set_licence' => function (array $input, Scenario $scenario): array {
                $scenario->state()->put('config', 'licence_valid', (bool) ($input['valid'] ?? true));

                return ['licence_valid' => (bool) ($input['valid'] ?? true)];
            },
            'set_usage' => function (array $input, Scenario $scenario): array {
                $account = $scenario->state()->get('accounts', (string) $input['user']);
                $scenario->state()->put('accounts', (string) $input['user'], [...(array) $account, 'diskused' => ((int) ($input['disk_mb'] ?? 0)).'M']);
                $scenario->state()->put('bandwidth', (string) $input['user'], (int) ($input['bandwidth_bytes'] ?? 0));

                return ['user' => $input['user']];
            },
        ];
    }

    /**
     * Reads only, against a real WHM named in the environment: WHM_RECORD_HOST, WHM_RECORD_TOKEN, and optionally
     * WHM_RECORD_PORT (2087), WHM_RECORD_USER (root) and WHM_RECORD_ACCOUNT (an account to summarise). Nothing on the
     * server changes.
     */
    public function recordLive(Recorder $recorder): void
    {
        (new LiveRecording)->run($recorder);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function addAccount(Scenario $scenario, array $input): array
    {
        $user = (string) $input['user'];
        $domain = strtolower((string) $input['domain']);
        $account = [
            'user' => $user,
            'domain' => $domain,
            'plan' => (string) ($input['plan'] ?? 'default'),
            'owner' => (string) ($input['owner'] ?? 'root'),
            'email' => (string) ($input['email'] ?? "owner@{$domain}"),
            'ip' => '203.0.113.20',
            'suspended' => 0,
            'is_locked' => 0,
            'suspendreason' => 'not suspended',
            'unix_startdate' => (int) ($input['unix_startdate'] ?? time()),
            'diskused' => (string) ($input['diskused'] ?? '0M'),
            'disklimit' => 'unlimited',
        ];
        $scenario->state()->put('accounts', $user, $account);
        $scenario->state()->put('zones', $domain, ['user' => $user]);

        return $account;
    }

    /**
     * @param  list<string>  $acls
     */
    public static function issueToken(Scenario $scenario, string $user, string $token, array $acls, ?int $expiresAt = null): string
    {
        $scenario->state()->put('tokens', hash('sha256', $token), [
            'user' => $user,
            'name' => 'billing',
            'create_time' => time(),
            'expires_at' => $expiresAt,
            'whitelist_ips' => ['127.0.0.1'],
            'acls' => $acls,
        ]);

        return $token;
    }
}
