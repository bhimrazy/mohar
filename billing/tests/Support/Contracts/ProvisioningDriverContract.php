<?php

namespace Tests\Support\Contracts;

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
use App\Data\Services\Provisioning\ClientSummary;
use App\Data\Services\Provisioning\CommandLookup;
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
use App\Support\Integrations\OutboundAddressGuard;
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
 * host resolves it. The kernel OutboundAddressGuard is bound with a resolver that answers `panel.contract.test`
 * with a public address, so a driver that obeys ADR 0015 (resolve() + pin()) runs through the real guard. Checks:
 * - identity, and resolution without queries or HTTP;
 * - typed settings: secrets are typed as secrets and have no default;
 * - lifecycle calls answer with exactly one outcome, report only documented data keys and never touch the database;
 * - whatever a driver echoes into its request, response or message is fully masked by the host's redaction set
 *   (ProvisioningContext::secrets()), including secret product and server settings;
 * - every capability the driver implements returns its documented type (one check per capability interface);
 * - a server whose hostname resolves to a forbidden address (cloud metadata) receives no request at all.
 */
final class ProvisioningDriverContract
{
    /** The public address `panel.contract.test` resolves to (documentation ranges are refused by the guard). */
    public const SERVER_IP = '93.184.216.34';

    /**
     * @param  (Closure(): void)|null  $arrange
     * @param  array<string, mixed>  $serverSettings  values for server fields whose placeholder would be invalid
     *                                                (a certificate pin, an enumerated region), replacing it
     */
    public static function verify(string $key, ?Closure $arrange = null, array $serverSettings = []): void
    {
        $setUp = function (string $resolvesTo = self::SERVER_IP) use ($arrange): void {
            Http::preventStrayRequests();
            app()->instance(OutboundAddressGuard::class, new OutboundAddressGuard(fn (string $host): array => $host === 'panel.contract.test' ? [$resolvesTo] : []));

            if ($arrange !== null) {
                $arrange();
            }
        };

        test("{$key} driver: is registered and resolves without I/O", function () use ($key, $setUp) {
            $setUp();
            $driver = null;
            $metadata = null;
            $queries = ProvisioningDriverContract::countQueries(function () use ($key, &$driver, &$metadata): void {
                $driver = app(ProvisioningDriverRegistry::class)->get($key);
                $metadata = $driver->metadata();
            });

            Http::assertNothingSent();
            expect($driver)->toBeInstanceOf(ProvisioningDriver::class)
                ->and($driver?->key())->toBe($key)
                ->and(trim((string) $driver?->name()))->not->toBe('')
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
                    expect($field->isSecret() || ! $redactor->isSensitiveKey($field->key))->toBeTrue("{$list}.{$field->key} looks like a secret but is typed {$field->type->value}");
                }
            }

            foreach ([$metadata->defaultPort, $metadata->defaultSecurePort] as $port) {
                expect($port === null || ($port >= 1 && $port <= 65535))->toBeTrue();
            }

            expect($metadata->commandTimeoutSeconds === null || $metadata->commandTimeoutSeconds > 0)->toBeTrue();
        });

        test("{$key} driver: lifecycle calls answer with one outcome, report only documented data and touch no database", function () use ($key, $setUp, $serverSettings) {
            $setUp();
            $driver = app(ProvisioningDriverRegistry::class)->get($key);
            $context = ProvisioningDriverContract::context($driver->metadata(), $serverSettings);
            $results = [];

            $queries = ProvisioningDriverContract::countQueries(function () use ($driver, $context, &$results): void {
                $results = [
                    'create' => $driver->create($context),
                    'suspend' => $driver->suspend($context, 'Overdue invoice'),
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

        test("{$key} driver: sends nothing to a server whose hostname resolves to a forbidden address", function () use ($key, $setUp, $serverSettings) {
            $setUp('169.254.169.254');
            $driver = app(ProvisioningDriverRegistry::class)->get($key);
            $context = ProvisioningDriverContract::context($driver->metadata(), $serverSettings);

            foreach (['create', 'unsuspend', 'terminate'] as $command) {
                try {
                    $driver->{$command}($context);
                } catch (\Throwable) {
                    // Refusing by exception is acceptable; sending is not.
                }
            }

            Http::assertNothingSent();
        });

        test("{$key} driver: everything it echoes is masked by the host's redaction set, secret settings included", function () use ($key, $setUp, $serverSettings) {
            $setUp();
            $driver = app(ProvisioningDriverRegistry::class)->get($key);
            $context = ProvisioningDriverContract::context($driver->metadata(), $serverSettings);
            $redactor = new Redactor;
            $secrets = $context->secrets();
            $result = $driver->create($context);
            $logged = json_encode([
                $redactor->redact($result->request, $secrets),
                $redactor->redact($result->response, $secrets),
                $redactor->maskValues((string) $result->message, $secrets),
            ]);

            expect($secrets)->toContain('Contract-Pass-123', 'Server-Pass-456', 'TOKEN-CONTRACT-789');

            foreach ($secrets as $secret) {
                expect($logged)->not->toContain($secret);
            }
        });

        test("{$key} driver: every implemented capability returns its documented type", function () use ($key, $setUp, $serverSettings) {
            $setUp();
            $registry = app(ProvisioningDriverRegistry::class);
            $driver = $registry->get($key);
            $context = ProvisioningDriverContract::context($driver->metadata(), $serverSettings);
            $checked = [];

            $queries = ProvisioningDriverContract::countQueries(function () use ($driver, $context, &$checked): void {
                foreach (ProvisioningDriverContract::capabilityChecks() as $interface => $check) {
                    if ($driver instanceof $interface) {
                        $check($driver, $context);
                        $checked[] = $interface;
                    }
                }
            });

            expect($checked)->toEqualCanonicalizing(array_values(array_intersect_key($registry->capabilities(), array_flip($registry->capabilitiesOf($key)))))
                ->and($queries)->toBe(0);
        });
    }

    /**
     * One check per capability interface. A new capability in ProvisioningDriverRegistry needs one here; the
     * completeness test in ProvisioningDriverContractTest holds the two lists together.
     *
     * @return array<class-string, Closure(object, ProvisioningContext): void>
     */
    public static function capabilityChecks(): array
    {
        return [
            ChangesPackage::class => function (ChangesPackage $driver, ProvisioningContext $context): void {
                expect($driver->changePackage($context, new PreviousPackage(6, BillingCycle::Monthly, ['package' => 'old']))->outcome())->toBe(DriverOutcome::Completed);
            },
            ChangesPassword::class => function (ChangesPassword $driver, ProvisioningContext $context): void {
                expect($driver->changePassword($context, 'N3w-Contract-Pass!')->outcome())->toBe(DriverOutcome::Completed);
            },
            RenewsAccounts::class => function (RenewsAccounts $driver, ProvisioningContext $context): void {
                expect($driver->renew($context)->outcome())->toBe(DriverOutcome::Completed);
            },
            TestsServerConnection::class => function (TestsServerConnection $driver, ProvisioningContext $context): void {
                expect($driver->testConnection(self::server($context)))->toBeInstanceOf(ConnectionCheck::class);
            },
            ReportsUsage::class => function (ReportsUsage $driver, ProvisioningContext $context): void {
                $rows = $driver->usage(self::server($context));
                expect($rows)->each->toBeInstanceOf(UsageRow::class)
                    ->and(array_filter(array_keys($rows), 'is_int'))->toBe([], 'usage rows are keyed by username');
            },
            ListsAccounts::class => function (ListsAccounts $driver, ProvisioningContext $context): void {
                expect($driver->listAccounts(self::server($context)))->toBeList()->each->toBeInstanceOf(RemoteAccount::class);
            },
            ProvidesFieldOptions::class => function (ProvidesFieldOptions $driver, ProvisioningContext $context): void {
                $fields = array_filter($driver->metadata()->productConfigFields, fn (SettingField $field): bool => $field->optionsSource === SettingField::OPTIONS_REMOTE);
                expect($fields)->not->toBeEmpty('a driver with field options declares at least one remote-filled field');

                foreach ($fields as $field) {
                    $options = $driver->fieldOptions(self::server($context), $field->key);
                    expect($options)->toBeArray()->each->toBeString();
                }
            },
            ReconcilesCommands::class => function (ReconcilesCommands $driver, ProvisioningContext $context): void {
                foreach (['create', 'suspend', 'unsuspend', 'terminate'] as $command) {
                    expect($driver->reconcile($context, $command, CarbonImmutable::now()->subMinutes(15)))->toBeInstanceOf(CommandLookup::class);
                }
            },
            ProvidesServiceSso::class => function (ProvidesServiceSso $driver, ProvisioningContext $context): void {
                $targets = $driver->ssoTargets();
                expect(array_filter(array_keys($targets), 'is_int'))->toBe([], 'SSO targets are keyed by target name')
                    ->and($targets)->each->toBeString();

                self::assertSsoLink($driver->serviceSsoLink($context));
                self::assertSsoLink($driver->serviceSsoLink($context, 'no-such-target'));

                foreach (array_keys($targets) as $target) {
                    self::assertSsoLink($driver->serviceSsoLink($context, (string) $target));
                }
            },
            ProvidesServerSso::class => function (ProvidesServerSso $driver, ProvisioningContext $context): void {
                self::assertSsoLink($driver->serverSsoLink(self::server($context)));
            },
        ];
    }

    /**
     * A plausible service on a plausible server, with every declared setting filled (secret ones with recognisable
     * values, so the redaction check can look for them).
     */
    /**
     * @param  array<string, mixed>  $serverSettings
     */
    public static function context(?DriverMetadata $metadata = null, array $serverSettings = []): ProvisioningContext
    {
        $productConfig = [];
        $secretValues = [];

        foreach ($metadata->productConfigFields ?? [] as $field) {
            $productConfig[$field->key] = $field->isSecret() ? "Contract-Secret-{$field->key}-42" : (array_key_first($field->options) ?? "contract-{$field->key}");

            if ($field->isSecret()) {
                $secretValues[] = $productConfig[$field->key];
            }
        }

        $settings = [];

        foreach ($metadata->serverFields ?? [] as $field) {
            $settings[$field->key] = $field->isSecret() ? "Contract-Server-Secret-{$field->key}-42" : (array_key_first($field->options) ?? "contract-{$field->key}");
        }

        $settings = [...$settings, ...$serverSettings];
        $secretSettingKeys = array_values(array_map(fn (SettingField $field): string => $field->key, array_filter($metadata->serverFields ?? [], fn (SettingField $field): bool => $field->isSecret())));

        return new ProvisioningContext(
            service: new ServiceSnapshot(
                id: 9001,
                clientId: 42,
                productId: 7,
                status: 'pending',
                domain: 'contract-test.example',
                username: 'contract',
                password: 'Contract-Pass-123',
                billingCycle: BillingCycle::Monthly,
                nextDueDate: CarbonImmutable::today()->addMonth()->toDateString(),
            ),
            server: new ServerConnection(
                id: 77,
                name: 'Contract server',
                hostname: 'panel.contract.test',
                ip: '192.0.2.10',
                port: $metadata->defaultSecurePort ?? null,
                secure: true,
                username: 'root',
                password: 'Server-Pass-456',
                apiToken: 'TOKEN-CONTRACT-789',
                settings: $settings,
                nameservers: ['ns1.contract.test', 'ns2.contract.test'],
                secretSettingKeys: $secretSettingKeys,
            ),
            client: new ClientSummary(42, 'Contract Client', 'client@contract.test', 'NP'),
            productConfig: $productConfig,
            customFields: ['hostname' => 'vps1.contract.test'],
            secretValues: $secretValues,
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
        $callback();

        return $count;
    }

    private static function server(ProvisioningContext $context): ServerConnection
    {
        return $context->server ?? throw new \LogicException('The contract context always has a server.');
    }

    private static function assertSsoLink(SsoLink $link): void
    {
        expect(str_starts_with($link->url, 'https://'))->toBeTrue('SSO links use https')
            ->and($link->expiresAt === null || $link->expiresAt->isFuture())->toBeTrue();
    }
}
