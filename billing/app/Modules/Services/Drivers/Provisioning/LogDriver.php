<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Contracts\Services\Provisioning\ProvidesFieldOptions;
use App\Contracts\Services\Provisioning\ProvidesServerSso;
use App\Contracts\Services\Provisioning\ProvidesServiceSso;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\Provisioning\ReconcilesCommands;
use App\Contracts\Services\Provisioning\RenewsAccounts;
use App\Contracts\Services\Provisioning\ReportsUsage;
use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\CommandLookup;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\RemoteAccount;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\SsoLink;
use App\Data\Services\Provisioning\UsageRow;
use App\Enums\FieldType;
use App\Enums\ReconcileStatus;
use App\Support\Services\RetryableProvisioningException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Demo and test driver, and the reference implementation of every provisioning capability: records each call,
 * keeps a pretend panel's accounts in the cache, and can be told to fail.
 *
 * Failure injection (tests): `app(LogDriver::class)->failNext('create', times: 2, retryable: true)`, or
 * `unknown: true` for a call whose outcome is unknown.
 * Failure injection (demo): a product setting `fail` naming the command, e.g. `['fail' => 'create']`.
 * Registered as a singleton so recorded calls are visible to the test that triggered them. The recorded calls and
 * injected failures are test-only state; real drivers are stateless (ProvisioningDriver). The pretend panel is a
 * read-modify-write on the cache without a lock, so concurrent workers can lose an update; acceptable for a demo,
 * and with a database cache store it touches the cache table (never Services' tables).
 */
class LogDriver implements ChangesPackage, ChangesPassword, ListsAccounts, ProvidesFieldOptions, ProvidesServerSso, ProvidesServiceSso, ProvisioningDriver, ReconcilesCommands, RenewsAccounts, ReportsUsage, TestsServerConnection
{
    /** Packages the pretend panel offers: name => [disk MB, bandwidth MB]. */
    private const PACKAGES = ['default' => [10240, 102400], 'business' => [51200, 512000]];

    /** @var list<array{command: string, service_id: ?int, server_id: ?int, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{times: int, retryable: bool, unknown: bool, message: string}> */
    private array $failures = [];

    /**
     * Runs during every recorded call, after the request is "sent" (tests simulate what happens elsewhere while a
     * driver call is in flight).
     *
     * @var (Closure(string, ?ProvisioningContext): void)|null
     */
    public ?Closure $whileRunning = null;

    public function key(): string
    {
        return 'log';
    }

    public function name(): string
    {
        return 'Log (demo)';
    }

    public function failNext(string $command, int $times = 1, bool $retryable = false, string $message = 'Simulated failure', bool $unknown = false): void
    {
        $this->failures[$command] = ['times' => $times, 'retryable' => $retryable, 'unknown' => $unknown, 'message' => $message];
    }

    /**
     * @return list<array{command: string, service_id: ?int, server_id: ?int, args: array<string, mixed>}>
     */
    public function callsFor(string $command): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $call['command'] === $command));
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            requiresServer: true,
            defaultPort: 2086,
            defaultSecurePort: 2087,
            productConfigFields: [
                new SettingField('package', 'Package name', FieldType::Select, help: 'A package on the server.', optionsSource: SettingField::OPTIONS_REMOTE),
                new SettingField('fail', 'Fail command (demo)', FieldType::Select, options: array_combine(
                    ['', 'create', 'suspend', 'unsuspend', 'terminate', 'change_package', 'change_password'],
                    ['', 'create', 'suspend', 'unsuspend', 'terminate', 'change_package', 'change_password'],
                )),
            ],
            serverFields: [
                new SettingField('region', 'Region (demo)', help: 'Shown on the module log only.'),
                new SettingField('webhook_secret', 'Webhook secret (demo)', FieldType::Secret, help: 'Stored encrypted; never shown again.'),
            ],
        );
    }

    public function testConnection(ServerConnection $server): ConnectionCheck
    {
        $failure = $this->record('test_connection', null, $server, $this->auth($server));

        return $failure === null
            ? new ConnectionCheck(true, "Connected to {$server->hostname}.")
            : new ConnectionCheck(false, (string) $failure->message);
    }

    public function create(ProvisioningContext $context): DriverResult
    {
        $request = $this->accountRequest($context) + ['password' => $context->service->password, 'package' => $context->productConfig['package'] ?? 'default'];

        if ($failure = $this->record('create', $context, $context->server, $request)) {
            return $failure;
        }

        if ($context->server !== null && $context->service->username !== null) {
            $this->rememberAccount($context->server->id, $context->service->username, $context->service->domain, (string) ($context->productConfig['package'] ?? 'default'));
        }

        return DriverResult::ok(
            'Account created.',
            ['dedicated_ip' => $context->service->dedicatedIp, 'driver_data' => ['remote_id' => 'log-'.$context->service->id]],
            $request,
            ['status' => 1, 'account' => $context->service->username],
        );
    }

    public function suspend(ProvisioningContext $context, string $reason): DriverResult
    {
        $request = $this->accountRequest($context) + ['reason' => $reason];

        if ($failure = $this->record('suspend', $context, $context->server, $request)) {
            return $failure;
        }

        $this->markSuspended($context, true);

        return DriverResult::ok('Account suspended.', request: $request, response: ['status' => 1]);
    }

    public function unsuspend(ProvisioningContext $context): DriverResult
    {
        $request = $this->accountRequest($context);

        if ($failure = $this->record('unsuspend', $context, $context->server, $request)) {
            return $failure;
        }

        $this->markSuspended($context, false);

        return DriverResult::ok('Account unsuspended.', request: $request, response: ['status' => 1]);
    }

    public function terminate(ProvisioningContext $context): DriverResult
    {
        $request = $this->accountRequest($context);

        if ($failure = $this->record('terminate', $context, $context->server, $request)) {
            return $failure;
        }

        if ($context->server !== null && $context->service->username !== null) {
            $this->forgetAccount($context->server->id, $context->service->username);
        }

        return DriverResult::ok('Account terminated.', request: $request, response: ['status' => 1]);
    }

    public function changePackage(ProvisioningContext $context, PreviousPackage $previous): DriverResult
    {
        $request = $this->accountRequest($context) + ['from_product' => $previous->productId, 'to_product' => $context->service->productId];

        return $this->record('change_package', $context, $context->server, $request) ?? DriverResult::ok('Package changed.', request: $request, response: ['status' => 1]);
    }

    public function changePassword(ProvisioningContext $context, #[\SensitiveParameter] string $newPassword): DriverResult
    {
        $request = $this->accountRequest($context) + ['password' => $newPassword];

        return $this->record('change_password', $context, $context->server, $request) ?? DriverResult::ok('Password changed.', request: $request, response: ['status' => 1]);
    }

    public function renew(ProvisioningContext $context): DriverResult
    {
        $request = $this->accountRequest($context) + ['until' => $context->service->nextDueDate];

        return $this->record('renew', $context, $context->server, $request) ?? DriverResult::ok('Account renewed.', request: $request, response: ['status' => 1]);
    }

    /**
     * Deterministic pretend usage for every account the pretend panel holds.
     */
    public function usage(ServerConnection $server): array
    {
        $this->record('usage', null, $server, []);
        $rows = [];

        foreach (array_keys($this->accounts($server->id)) as $username) {
            $seed = crc32((string) $username);
            $rows[(string) $username] = new UsageRow(
                diskUsedMb: 200 + $seed % 4800,
                diskLimitMb: 10240,
                bandwidthUsedMb: 1000 + $seed % 40000,
                bandwidthLimitMb: 102400,
            );
        }

        return $rows;
    }

    public function listAccounts(ServerConnection $server): array
    {
        $this->record('list_accounts', null, $server, []);
        $accounts = [];

        foreach ($this->accounts($server->id) as $username => $account) {
            $accounts[] = $this->remoteAccount((string) $username, $account, $server);
        }

        return $accounts;
    }

    public function fieldOptions(ServerConnection $server, string $fieldKey): array
    {
        $this->record('field_options', null, $server, ['field' => $fieldKey]);

        if ($fieldKey !== 'package') {
            return [];
        }

        $options = [];

        foreach (self::PACKAGES as $name => [$diskMb, $bandwidthMb]) {
            $options[$name] = sprintf('%s (%d GB disk, %d GB bandwidth)', $name, intdiv($diskMb, 1024), intdiv($bandwidthMb, 1024));
        }

        return $options;
    }

    /**
     * Reads the pretend panel: create ran when our account is there, terminate when it is gone, suspend and unsuspend
     * by the account's suspended flag. Other commands leave nothing to check.
     */
    public function reconcile(ProvisioningContext $context, string $command, CarbonImmutable $attemptedAt): CommandLookup
    {
        $this->record('reconcile', $context, $context->server, ['command' => $command, 'attempted_at' => $attemptedAt->toIso8601String()]);
        $username = $context->service->username;

        if ($context->server === null || $username === null) {
            return new CommandLookup(ReconcileStatus::Unknown, message: 'The service has no server or username to look up.');
        }

        $account = $this->accounts($context->server->id)[$username] ?? null;
        $remote = $account === null ? null : $this->remoteAccount($username, $account, $context->server);

        return match (true) {
            $command === 'create' && $account === null => new CommandLookup(ReconcileStatus::NotExecuted),
            $command === 'create' && $account['domain'] !== $context->service->domain => new CommandLookup(ReconcileStatus::Conflict, $remote, "{$username} exists for {$account['domain']}."),
            $command === 'create' => new CommandLookup(ReconcileStatus::Executed, $remote),
            $command === 'terminate' => new CommandLookup($account === null ? ReconcileStatus::Executed : ReconcileStatus::NotExecuted, $remote),
            in_array($command, ['suspend', 'unsuspend'], true) && $account === null => new CommandLookup(ReconcileStatus::Conflict, message: "{$username} is not on the server."),
            in_array($command, ['suspend', 'unsuspend'], true) => new CommandLookup($account['suspended'] === ($command === 'suspend') ? ReconcileStatus::Executed : ReconcileStatus::NotExecuted, $remote),
            default => new CommandLookup(ReconcileStatus::Unknown, $remote, "The log driver cannot tell whether {$command} ran."),
        };
    }

    public function ssoTargets(): array
    {
        return ['email' => 'Email accounts', 'files' => 'File manager'];
    }

    public function serviceSsoLink(ProvisioningContext $context, ?string $target = null): SsoLink
    {
        $this->record('service_sso', $context, $context->server, $this->accountRequest($context) + ['target' => $target]);

        return new SsoLink('https://'.($context->server->hostname ?? 'panel.invalid').'/login?session=log-'.$context->service->id, CarbonImmutable::now()->addMinutes(5));
    }

    public function serverSsoLink(ServerConnection $server): SsoLink
    {
        $this->record('server_sso', null, $server, $this->auth($server));

        return new SsoLink("https://{$server->hostname}/login?session=log-server-{$server->id}", CarbonImmutable::now()->addMinutes(5));
    }

    /**
     * Put an account on the pretend panel without a create call (tests of usage, listing and reconcile).
     */
    public function rememberAccount(int $serverId, string $username, ?string $domain = null, string $package = 'default', bool $suspended = false): void
    {
        Cache::forever($this->accountsKey($serverId), [...$this->accounts($serverId), $username => ['domain' => $domain, 'package' => $package, 'suspended' => $suspended]]);
    }

    /**
     * Forget recorded calls and injected failures (tests).
     */
    public function reset(): void
    {
        $this->calls = [];
        $this->failures = [];
        $this->whileRunning = null;
    }

    /**
     * Whether any call was recorded for the service.
     */
    public function wasCalledFor(int $serviceId, string $command): bool
    {
        return collect($this->callsFor($command))->contains(fn (array $call): bool => $call['service_id'] === $serviceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountRequest(ProvisioningContext $context): array
    {
        return [
            'hostname' => $context->server?->hostname,
            'auth' => $context->server === null ? null : $this->auth($context->server)['auth'],
            'account' => $context->service->username,
            'domain' => $context->service->domain,
        ];
    }

    /**
     * @return array{hostname: string, auth: array{username: ?string, password: ?string}}
     */
    private function auth(ServerConnection $server): array
    {
        return ['hostname' => $server->hostname, 'auth' => ['username' => $server->username, 'password' => $server->password]];
    }

    /**
     * Record the call; return a failure result (or throw) when a failure was injected.
     *
     * @param  array<string, mixed>  $request
     */
    private function record(string $command, ?ProvisioningContext $context, ?ServerConnection $server, array $request): ?DriverResult
    {
        $this->calls[] = ['command' => $command, 'service_id' => $context?->service->id, 'server_id' => $server?->id, 'args' => $request];

        if ($this->whileRunning !== null) {
            ($this->whileRunning)($command, $context);
        }

        if (($context?->productConfig['fail'] ?? null) === $command) {
            return DriverResult::fail("Simulated {$command} failure (product setting).", request: $request);
        }

        if (! isset($this->failures[$command]) || $this->failures[$command]['times'] < 1) {
            return null;
        }

        $failure = $this->failures[$command];
        $this->failures[$command]['times']--;

        return match (true) {
            $failure['retryable'] => throw new RetryableProvisioningException($failure['message']),
            $failure['unknown'] => DriverResult::unknown($failure['message'], request: $request),
            default => DriverResult::fail($failure['message'], request: $request),
        };
    }

    /**
     * @return array<string, array{domain: ?string, package: string, suspended: bool}>
     */
    private function accounts(int $serverId): array
    {
        /** @var array<string, array{domain: ?string, package: string, suspended: bool}> */
        return Cache::get($this->accountsKey($serverId), []);
    }

    /**
     * @param  array{domain: ?string, package: string, suspended: bool}  $account
     */
    private function remoteAccount(string $username, array $account, ServerConnection $server): RemoteAccount
    {
        return new RemoteAccount(
            username: $username,
            domain: $account['domain'],
            plan: $account['package'],
            owner: $server->username ?? 'root',
            suspended: $account['suspended'],
        );
    }

    private function markSuspended(ProvisioningContext $context, bool $suspended): void
    {
        $username = $context->service->username;

        if ($context->server === null || $username === null || ! isset($this->accounts($context->server->id)[$username])) {
            return;
        }

        $accounts = $this->accounts($context->server->id);
        $accounts[$username]['suspended'] = $suspended;
        Cache::forever($this->accountsKey($context->server->id), $accounts);
    }

    private function forgetAccount(int $serverId, string $username): void
    {
        $accounts = $this->accounts($serverId);
        unset($accounts[$username]);
        Cache::forever($this->accountsKey($serverId), $accounts);
    }

    private function accountsKey(int $serverId): string
    {
        return "services:log-driver:server:{$serverId}:accounts";
    }
}
