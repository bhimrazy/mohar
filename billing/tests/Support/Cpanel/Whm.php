<?php

namespace Tests\Support\Cpanel;

use App\Data\Services\Provisioning\ClientSummary;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\ServiceSnapshot;
use App\Support\Integrations\OutboundAddressGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A pretend WHM for the cPanel driver tests: hand-written WHM API 1 fixtures (tests/Fixtures/Cpanel, written from the
 * public API reference, never copied from a real server), Http::fake routes by function name, and snapshots.
 */
final class Whm
{
    public const HOST = 'whm.example.test';

    public const IP = '93.184.216.34';

    public const TOKEN = 'FAKE-WHM-TOKEN-0123456789ABCDEF';

    public const PASSWORD = 'Fake-Account-Pass-42!';

    /**
     * @param  array<string, mixed>  $overrides  merged into the fixture (array_replace_recursive)
     * @return array<string, mixed>
     */
    public static function fixture(string $name, array $overrides = []): array
    {
        $decoded = json_decode((string) file_get_contents(base_path("tests/Fixtures/Cpanel/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);

        return array_replace_recursive($decoded, $overrides);
    }

    public static function raw(string $file): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/Cpanel/{$file}"));
    }

    /**
     * Fake WHM: function => fixture name, fixture array, Http response, sequence or closure. Functions not listed
     * answer with the success fixture of the same name when one exists (`<fn>` or `<fn>_ok`). Binds a guard that
     * resolves HOST to IP.
     *
     * @param  array<string, mixed>  $routes
     */
    public static function fake(array $routes = [], ?string $resolvesTo = self::IP): void
    {
        // A fresh factory, so a second fake in one test replaces the first instead of queueing behind it.
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();

        if ($resolvesTo !== null) {
            self::bindGuard($resolvesTo);
        }

        $fake = [];

        foreach ($routes as $function => $response) {
            $fake["*/json-api/{$function}*"] = is_string($response) ? self::fixture($response) : $response;
        }

        foreach (self::defaults() as $function => $fixture) {
            $fake["*/json-api/{$function}*"] ??= self::fixture($fixture);
        }

        Http::fake($fake);
    }

    public static function bindGuard(string $resolvesTo = self::IP): void
    {
        app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard(fn (string $host): array => in_array($host, [self::HOST, 'panel.contract.test'], true) ? [$resolvesTo] : []));
    }

    /**
     * Success answers for the contract dataset: every call succeeds for the contract's service and package. The
     * contract binds its own guard (including the forbidden-address case), so this one binds none.
     */
    public static function contract(): void
    {
        $account = ['user' => 'contract', 'domain' => 'contract-test.example', 'plan' => 'contract-package', 'owner' => 'root'];

        self::fake([
            'listpkgs' => self::fixture('listpkgs', ['data' => ['pkg' => [4 => ['name' => 'contract-package', 'QUOTA' => '1024', 'BWLIMIT' => '10240']]]]),
            'accountsummary' => self::fixture('accountsummary', ['data' => ['acct' => [0 => $account]]]),
            'listaccts' => self::fixture('listaccts_example', ['data' => ['acct' => [0 => $account]]]),
            'create_user_session' => self::fixture('create_user_session', ['data' => [
                'expires' => now()->addMinutes(15)->getTimestamp(),
                'url' => 'https://panel.contract.test:2083/cpsess1234567890/login/?session=contract%3aAbC',
            ]]),
        ], resolvesTo: null);
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'version' => 'version',
            'myprivs' => 'myprivs_root',
            'api_token_get_details' => 'api_token_get_details',
            'acctcounts' => 'acctcounts',
            'verify_new_username' => 'verify_new_username_ok',
            'createacct' => 'createacct_ok',
            'accountsummary' => 'accountsummary',
            'suspendacct' => 'suspendacct_ok',
            'unsuspendacct' => 'unsuspendacct_ok',
            'removeacct' => 'removeacct_ok',
            'changepackage' => 'changepackage_ok',
            'passwd' => 'passwd_ok',
            'listaccts' => 'listaccts_example',
            'listpkgs' => 'listpkgs',
            'showbw' => 'showbw',
            'get_nameserver_config' => 'get_nameserver_config',
            'create_user_session' => 'create_user_session',
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function server(array $settings = [], ?int $port = null, string $username = 'root', ?string $hostname = self::HOST): ServerConnection
    {
        return new ServerConnection(
            id: 5,
            name: 'Shared 1',
            hostname: (string) $hostname,
            ip: '203.0.113.5',
            port: $port,
            username: $username,
            password: 'Fake-Root-Password-7',
            apiToken: self::TOKEN,
            settings: $settings,
            nameservers: ['ns1.example-host.test', 'ns2.example-host.test'],
        );
    }

    /**
     * @param  array<string, mixed>  $productConfig
     * @param  array<string, mixed>  $configOptions
     */
    public static function context(array $productConfig = ['package' => 'root_basic'], ?ServerConnection $server = null, string $username = 'example', ?string $domain = 'example-shop.com', array $configOptions = [], ?CarbonImmutable $unknownAttemptAt = null, ?CarbonImmutable $deadline = null): ProvisioningContext
    {
        return new ProvisioningContext(
            service: new ServiceSnapshot(id: 31, clientId: 8, productId: 3, status: 'pending', domain: $domain, username: $username, password: self::PASSWORD),
            server: $server ?? self::server(),
            client: new ClientSummary(8, 'Example Shop', 'owner@example-shop.com', 'NP'),
            productConfig: $productConfig,
            configOptions: $configOptions,
            deadline: $deadline,
            unknownAttemptAt: $unknownAttemptAt,
        );
    }

    /**
     * A listaccts answer holding one account (overrides on the example account).
     *
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    public static function accounts(array $account): array
    {
        return self::fixture('listaccts_example', ['data' => ['acct' => [0 => $account]]]);
    }

    /**
     * Requests sent to one WHM function, in order.
     *
     * @return list<Request>
     */
    public static function sent(string $function): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/json-api/{$function}"))
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    /**
     * The parameters of a request, from its query (GET) or form body (POST).
     *
     * @return array<string, mixed>
     */
    public static function params(Request $request): array
    {
        $query = [];

        // parse_str() would turn the dots in `api.version` and `api.chunk.*` into underscores.
        foreach (array_filter(explode('&', (string) parse_url($request->url(), PHP_URL_QUERY))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[urldecode($key)] = urldecode($value);
        }

        return [...$query, ...($request->method() === 'POST' ? $request->data() : [])];
    }
}
