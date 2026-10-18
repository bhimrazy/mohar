<?php

namespace App\Modules\Services\Drivers\Provisioning;

use App\Modules\Services\Exceptions\RetryableProvisioningException;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;

/**
 * Demo/test driver: records every call and can be told to fail.
 *
 * Failure injection (tests): `app(LogDriver::class)->failNext('create', times: 2, retryable: true)`.
 * Failure injection (demo): a product driver_config `fail` key naming the command, e.g. `['fail' => 'create']`.
 * Registered as a singleton so recorded calls are visible to the test that triggered them.
 */
class LogDriver implements ProvisioningDriver
{
    /** @var list<array{command: string, service_id: ?int, server_id: ?int, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{times: int, retryable: bool, message: string}> */
    private array $failures = [];

    /**
     * Runs during every recorded call, after the request is "sent" (tests simulate what happens
     * elsewhere while a driver call is in flight).
     *
     * @var (\Closure(string, ?ServiceContext): void)|null
     */
    public ?\Closure $whileRunning = null;

    public function failNext(string $command, int $times = 1, bool $retryable = false, string $message = 'Simulated failure'): void
    {
        $this->failures[$command] = ['times' => $times, 'retryable' => $retryable, 'message' => $message];
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
            'log',
            'Log (demo)',
            requiresServer: true,
            defaultPort: 2086,
            defaultSecurePort: 2087,
            capabilities: [DriverMetadata::CHANGE_PACKAGE, DriverMetadata::CHANGE_PASSWORD, DriverMetadata::USAGE],
            productConfigFields: [
                ['key' => 'package', 'label' => 'Package name', 'type' => 'text', 'required' => false, 'secret' => false],
                ['key' => 'fail', 'label' => 'Fail command (demo)', 'type' => 'select', 'required' => false, 'secret' => false, 'options' => ['', 'create', 'suspend', 'unsuspend', 'terminate', 'change_package', 'change_password']],
            ],
            serverFields: [
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true, 'secret' => false],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true],
            ],
        );
    }

    public function testConnection(Server $server): DriverResult
    {
        return $this->record('test_connection', null, $server, ['hostname' => $server->hostname, 'username' => $server->username, 'password' => $server->password])
            ?? DriverResult::ok("Connected to {$server->hostname}.", request: ['hostname' => $server->hostname, 'username' => $server->username, 'password' => $server->password]);
    }

    public function create(ServiceContext $context): DriverResult
    {
        $request = $this->accountRequest($context) + ['password' => $context->service->password, 'package' => $context->productConfig['package'] ?? 'default'];

        return $this->record('create', $context, $context->server, $request) ?? DriverResult::ok(
            'Account created.',
            ['dedicated_ip' => $context->service->dedicated_ip, 'driver_data' => ['remote_id' => 'log-'.$context->service->id]],
            $request,
            ['status' => 1, 'account' => $context->service->username],
        );
    }

    public function suspend(ServiceContext $context, string $reason): DriverResult
    {
        $request = $this->accountRequest($context) + ['reason' => $reason];

        return $this->record('suspend', $context, $context->server, $request) ?? DriverResult::ok('Account suspended.', request: $request, response: ['status' => 1]);
    }

    public function unsuspend(ServiceContext $context): DriverResult
    {
        $request = $this->accountRequest($context);

        return $this->record('unsuspend', $context, $context->server, $request) ?? DriverResult::ok('Account unsuspended.', request: $request, response: ['status' => 1]);
    }

    public function terminate(ServiceContext $context): DriverResult
    {
        $request = $this->accountRequest($context);

        return $this->record('terminate', $context, $context->server, $request) ?? DriverResult::ok('Account terminated.', request: $request, response: ['status' => 1]);
    }

    public function changePackage(ServiceContext $context, array $previous): DriverResult
    {
        $request = $this->accountRequest($context) + ['from_product' => $previous['product_id'] ?? null, 'to_product' => $context->service->product_id];

        return $this->record('change_package', $context, $context->server, $request) ?? DriverResult::ok('Package changed.', request: $request, response: ['status' => 1]);
    }

    public function changePassword(ServiceContext $context, string $newPassword): DriverResult
    {
        $request = $this->accountRequest($context) + ['password' => $newPassword];

        return $this->record('change_password', $context, $context->server, $request) ?? DriverResult::ok('Password changed.', request: $request, response: ['status' => 1]);
    }

    public function usage(Server $server): array
    {
        $this->calls[] = ['command' => 'usage', 'service_id' => null, 'server_id' => $server->id, 'args' => []];

        return $server->services()->whereNotNull('username')->pluck('username', 'id')
            ->mapWithKeys(fn (string $username, int $id): array => [$username => new UsageRow(
                diskUsedMb: 200 + ($id * 137) % 4800,
                diskLimitMb: 10240,
                bandwidthUsedMb: 1000 + ($id * 911) % 40000,
                bandwidthLimitMb: 102400,
            )])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountRequest(ServiceContext $context): array
    {
        return [
            'hostname' => $context->server?->hostname,
            'auth' => ['username' => $context->server?->username, 'password' => $context->server?->password],
            'account' => $context->service->username,
            'domain' => $context->service->domain,
        ];
    }

    /**
     * Record the call; return a failure result (or throw) when a failure was injected.
     *
     * @param  array<string, mixed>  $request
     */
    private function record(string $command, ?ServiceContext $context, ?Server $server, array $request): ?DriverResult
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

        if ($failure['retryable']) {
            throw new RetryableProvisioningException($failure['message']);
        }

        return DriverResult::fail($failure['message'], request: $request);
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
    public function wasCalledFor(Service $service, string $command): bool
    {
        return collect($this->callsFor($command))->contains(fn (array $call): bool => $call['service_id'] === $service->id);
    }
}
