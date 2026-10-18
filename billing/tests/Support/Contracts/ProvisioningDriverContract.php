<?php

namespace Tests\Support\Contracts;

use App\Contracts\Services\Provisioning\ChangesPackage;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Contracts\Services\Provisioning\ProvidesServerSso;
use App\Contracts\Services\Provisioning\ProvidesServiceSso;
use App\Contracts\Services\Provisioning\ProvisioningDriver;
use App\Contracts\Services\Provisioning\RenewsAccounts;
use App\Contracts\Services\Provisioning\ReportsUsage;
use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\ClientSummary;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Data\Services\Provisioning\DriverResult;
use App\Data\Services\Provisioning\PreviousPackage;
use App\Data\Services\Provisioning\ProvisioningContext;
use App\Data\Services\Provisioning\RemoteAccount;
use App\Data\Services\Provisioning\ServerConnection;
use App\Data\Services\Provisioning\ServiceSnapshot;
use App\Data\Services\Provisioning\SsoLink;
use App\Data\Services\Provisioning\UsageRow;
use App\Enums\BillingCycle;
use App\Enums\DriverOutcome;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The contract every provisioning driver passes (ADR 0009). Call it from a test file, once per driver key:
 *
 *   ProvisioningDriverContract::verify('cpanel', arrange: fn () => Http::fake([...]));
 *
 * `arrange` runs before each check and fakes the remote system so every call succeeds; failure modes are
 * driver-specific and belong in the driver's own tests. The driver is resolved through the kernel registry, as the
 * host resolves it. Checks: identity and cheap resolution; typed settings with secrets typed as secrets; lifecycle
 * calls answer with exactly one outcome, report only documented data keys and never touch the database; every
 * capability the driver implements returns its documented type.
 */
final class ProvisioningDriverContract
{
    /**
     * @param  (Closure(): void)|null  $arrange
     */
    public static function verify(string $key, ?Closure $arrange = null): void
    {
        $setUp = function () use ($arrange): void {
            Http::preventStrayRequests();

            if ($arrange !== null) {
                $arrange();
            }
        };

        test("{$key} driver: is registered and resolves without I/O", function () use ($key, $setUp) {
            $setUp();
            $queries = ProvisioningDriverContract::countQueries(function () use ($key, &$driver, &$metadata): void {
                $driver = app(ProvisioningDriverRegistry::class)->get($key);
                $metadata = $driver->metadata();
            });

            expect($driver)->toBeInstanceOf(ProvisioningDriver::class)
                ->and($driver->key())->toBe($key)
                ->and(trim($driver->name()))->not->toBe('')
                ->and($metadata)->toBeInstanceOf(DriverMetadata::class)
                ->and($queries)->toBe(0);
        });

        test("{$key} driver: declares typed settings and types every secret as one", function () use ($key, $setUp) {
            $setUp();
            $metadata = app(ProvisioningDriverRegistry::class)->metadata($key);
            $redactor = new Redactor;

            foreach (['productConfigFields' => $metadata->productConfigFields, 'serverFields' => $metadata->serverFields] as $list => $fields) {
                expect($fields)->toBeList()->each->toBeInstanceOf(SettingField::class);
                $keys = array_map(fn (SettingField $field): string => $field->key, $fields);
                expect($keys)->toBe(array_values(array_unique($keys)), "{$list} has duplicate keys");

                foreach ($fields as $field) {
                    expect($field->isSecret() || ! $redactor->isSensitiveKey($field->key))->toBeTrue("{$list}.{$field->key} looks like a secret but is typed {$field->type->value}")
                        ->and($field->isSecret() && $field->default !== null)->toBeFalse("{$list}.{$field->key} is a secret with a default");
                }
            }

            foreach ([$metadata->defaultPort, $metadata->defaultSecurePort] as $port) {
                expect($port === null || ($port >= 1 && $port <= 65535))->toBeTrue();
            }
        });

        test("{$key} driver: lifecycle calls answer with one outcome, report only documented data and touch no database", function () use ($key, $setUp) {
            $setUp();
            $driver = app(ProvisioningDriverRegistry::class)->get($key);
            $context = ProvisioningDriverContract::context($driver->metadata()->requiresServer);
            $results = [];

            $queries = ProvisioningDriverContract::countQueries(function () use ($driver, $context, &$results): void {
                $results = [
                    'create' => $driver->create($context),
                    'suspend' => $driver->suspend($context, 'overdue'),
                    'unsuspend' => $driver->unsuspend($context),
                    'terminate' => $driver->terminate($context),
                ];
            });

            foreach ($results as $command => $result) {
                expect($result)->toBeInstanceOf(DriverResult::class)
                    ->and($result->outcome())->toBe(DriverOutcome::Completed, "{$command}: {$result->message}")
                    ->and(array_diff(array_keys($result->data), DriverResult::DATA_KEYS))->toBe([], "{$command} reports undocumented data keys");
            }

            expect($results['create']->data['driver_data'] ?? [])->toBeArray()
                ->and($queries)->toBe(0);
        });

        test("{$key} driver: every implemented capability returns its documented type", function () use ($key, $setUp) {
            $setUp();
            $driver = app(ProvisioningDriverRegistry::class)->get($key);
            $context = ProvisioningDriverContract::context(true);
            $server = $context->server;
            assert($server instanceof ServerConnection);
            $checked = 0;

            $queries = ProvisioningDriverContract::countQueries(function () use ($driver, $context, $server, &$checked): void {
                if ($driver instanceof ChangesPackage) {
                    expect($driver->changePackage($context, new PreviousPackage(6, BillingCycle::Monthly))->outcome())->toBe(DriverOutcome::Completed);
                    $checked++;
                }

                if ($driver instanceof ChangesPassword) {
                    expect($driver->changePassword($context, 'N3w-Contract-Pass!')->outcome())->toBe(DriverOutcome::Completed);
                    $checked++;
                }

                if ($driver instanceof RenewsAccounts) {
                    expect($driver->renew($context)->outcome())->toBe(DriverOutcome::Completed);
                    $checked++;
                }

                if ($driver instanceof TestsServerConnection) {
                    expect($driver->testConnection($server))->toBeInstanceOf(ConnectionCheck::class);
                    $checked++;
                }

                if ($driver instanceof ReportsUsage) {
                    $rows = $driver->usage($server);
                    expect($rows)->each->toBeInstanceOf(UsageRow::class)
                        ->and(array_filter(array_keys($rows), 'is_int'))->toBe([], 'usage rows are keyed by username');
                    $checked++;
                }

                if ($driver instanceof ListsAccounts) {
                    expect($driver->listAccounts($server))->toBeList()->each->toBeInstanceOf(RemoteAccount::class);
                    $checked++;
                }

                foreach ([
                    $driver instanceof ProvidesServiceSso ? $driver->serviceSsoLink($context) : null,
                    $driver instanceof ProvidesServerSso ? $driver->serverSsoLink($server) : null,
                ] as $link) {
                    if ($link === null) {
                        continue;
                    }

                    expect($link)->toBeInstanceOf(SsoLink::class)
                        ->and(str_starts_with($link->url, 'https://'))->toBeTrue('SSO links use https')
                        ->and($link->expiresAt === null || $link->expiresAt->isFuture())->toBeTrue();
                    $checked++;
                }
            });

            expect($checked)->toBe(count(app(ProvisioningDriverRegistry::class)->capabilitiesOf($key)))
                ->and($queries)->toBe(0);
        });
    }

    /**
     * A plausible service on a plausible server.
     */
    public static function context(bool $withServer = true): ProvisioningContext
    {
        return new ProvisioningContext(
            service: new ServiceSnapshot(
                id: 9001,
                clientId: 42,
                productId: 7,
                status: 'pending',
                domain: 'contract-test.example',
                username: 'contract',
                password: 'Contract-Pass-123',
                dedicatedIp: null,
                assignedIps: [],
                driverData: [],
                billingCycle: BillingCycle::Monthly,
                nextDueDate: CarbonImmutable::today()->addMonth()->toDateString(),
            ),
            server: $withServer ? new ServerConnection(
                id: 77,
                name: 'Contract server',
                hostname: 'panel.contract.test',
                ip: '192.0.2.10',
                port: 2087,
                secure: true,
                username: 'root',
                password: 'Server-Pass-456',
                apiToken: 'TOKEN-CONTRACT-789',
                nameservers: ['ns1.contract.test', 'ns2.contract.test'],
            ) : null,
            client: new ClientSummary(42, 'Contract Client', 'client@contract.test', 'NP'),
            productConfig: ['package' => 'starter'],
        );
    }

    /**
     * @param  Closure(): void  $callback
     */
    public static function countQueries(Closure $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $before = $count;
        $callback();

        return $count - $before;
    }
}
