<?php

namespace Tests\Support\Contracts;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\DeclaresTldAttributes;
use App\Contracts\Domains\Registrar\LinksExistingOrders;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Contracts\Domains\Registrar\ManagesIdProtection;
use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
use App\Contracts\Domains\Registrar\ReconcilesSubmissions;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\RestoresDomains;
use App\Contracts\Domains\Registrar\SuggestsNames;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Contracts\Domains\Registrar\ValidatesTldAttributes;
use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\EppCodeResult;
use App\Data\Domains\Registrar\LinkedOrder;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Domains\Registrar\SyncResult;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Domains\Actions\BuildDomainContacts;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\CapabilityNotSupported;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use App\Support\Integrations\Redactor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Fakes\Domains\FakeRegistrar;
use Throwable;

/**
 * The contract every registrar driver passes (ADR 0009, registrars.md §7). Call it from a test file, once per key:
 *
 *   RegistrarDriverContract::verify('resellerclub', arrange: fn () => Http::fake([...]));
 *
 * `arrange` runs before each check and fakes the remote system so calls succeed; stray HTTP fails the test. The
 * driver is resolved through the kernel registry and called with DTOs only, so verify() needs no module: it runs
 * with only Core loaded. Checks:
 * - identity, and resolution without queries or HTTP;
 * - typed settings: credential-like keys are secrets, and secrets have no default;
 * - the core calls and every capability the driver implements answer with their documented type or a
 *   RegistrarException (rejected, retryable, RegistrarTimeout), never another error; a write is completed or
 *   pending; nothing touches the database (one entry per capability interface in calls());
 * - no answer or error text contains a configured secret setting or a request secret (the transfer code), checked
 *   raw, before any host masking (assertEchoesNoSecret()).
 * verifyHost() adds the Domains host path and needs the Domains module. Response-mapping scenarios of API drivers
 * (availability and status matrices, timeouts, refused connections, fixtures) belong in the driver's own tests.
 */
final class RegistrarDriverContract
{
    /** Credential values a driver might echo back; none may be logged. */
    public const SECRETS = [
        'api-key' => 'contract-api-key-1', 'passwd' => 'contract-passwd-2', 'signature' => 'contract-signature-3',
        'domsecret' => 'contract-domsecret-4', 'auth-code' => 'contract-auth-code-5', 'epp_code' => 'contract-epp-6',
    ];

    /**
     * @param  (Closure(): void)|null  $arrange
     */
    public static function verify(string $key, ?Closure $arrange = null): void
    {
        $setUp = function () use ($key, $arrange): RegistrarDriver {
            Http::preventStrayRequests();

            if ($arrange !== null) {
                $arrange();
            }

            return app(RegistrarDriverRegistry::class)->get($key);
        };

        test("{$key} registrar: is registered and resolves without I/O", function () use ($key, $setUp) {
            $driver = null;
            $queries = ProvisioningDriverContract::countQueries(function () use ($setUp, &$driver): void {
                $driver = $setUp();
            });

            Http::assertNothingSent();
            expect($driver?->key())->toBe($key)
                ->and(trim((string) $driver?->name()))->not->toBe('')
                ->and($queries)->toBe(0);
        });

        test("{$key} registrar: declares typed settings and types every credential as a secret", function () use ($setUp) {
            $fields = $setUp()->settingsFields();
            $redactor = new Redactor;
            expect($fields)->toBeList()->each->toBeInstanceOf(SettingField::class);
            $keys = array_map(fn (SettingField $field): string => $field->key, $fields);
            expect($keys)->toBe(array_values(array_unique($keys)), 'settings have duplicate keys');

            foreach ($fields as $field) {
                expect($field->isSecret() || ! $redactor->isSensitiveKey($field->key))->toBeTrue("{$field->key} reads like a credential but is typed {$field->type->value}")
                    ->and($field->isSecret() && $field->default !== null)->toBeFalse("secret {$field->key} has a default");
            }
        });

        test("{$key} registrar: every call answers with one documented outcome and touches no database", function () use ($key, $setUp) {
            $driver = $setUp();
            $registry = app(RegistrarDriverRegistry::class);
            $checked = [];

            $queries = ProvisioningDriverContract::countQueries(function () use ($driver, &$checked): void {
                foreach (RegistrarDriverContract::runs($driver) as $interface => $runs) {
                    foreach ($runs as $name => [$expected, $run]) {
                        RegistrarDriverContract::assertOutcome($name, $expected, $run);
                    }

                    $checked[] = $interface;
                }
            });

            expect($checked)->toEqualCanonicalizing(array_values(array_unique(array_intersect_key($registry->capabilities(), array_flip($registry->capabilitiesOf($key))))))
                ->and($queries)->toBe(0);
        });

        test("{$key} registrar: echoes no account credential and no request secret, in any answer or error", function () use ($setUp) {
            RegistrarDriverContract::assertEchoesNoSecret($setUp());
        });
    }

    /**
     * Run every call and fail if a result or error text contains a configured secret setting or a request secret
     * (the transfer code). Checked raw: the host masks what it logs and shows, but a driver must not rely on that, and
     * not every path (a connection test, a package host) goes through the same mask.
     */
    public static function assertEchoesNoSecret(RegistrarDriver $driver): void
    {
        $secrets = [...self::config($driver)->secrets(), ...self::request(self::domain())->secrets()];
        $seen = [];

        foreach (self::runs($driver) as $runs) {
            foreach ($runs as $name => [, $run]) {
                try {
                    $result = $run();
                    $seen[$name] = is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : $result;
                } catch (Throwable $error) {
                    $seen[$name] = $error->getMessage();
                }
            }
        }

        expect(self::config($driver)->secrets())->not->toBeEmpty('the contract configures a value in every secret setting');

        foreach ($seen as $name => $answer) {
            foreach ($secrets as $secret) {
                expect(str_contains((string) json_encode($answer), $secret))->toBeFalse("{$name} echoes a secret");
            }
        }
    }

    /**
     * The Domains host path (needs the Domains module): with the account configured with known credentials, neither
     * they nor credential-named pairs reach registrar_command_log, the domain's last_error, the activity log or
     * RegistrarActionFailed (C5), on the real driver's submit path and on a rejected and a lost register whose error
     * text echoes them. `failing` makes the real driver fail that way (an API driver fakes its provider's error body);
     * by default a scriptable driver takes its place for the failure.
     *
     * @param  (Closure(): void)|null  $arrange
     * @param  (Closure(string, Throwable): void)|null  $failing
     */
    public static function verifyHost(string $key, ?Closure $arrange = null, string $extension = '.com', ?Closure $failing = null): void
    {
        test("{$key} registrar: configured credentials never reach the command log, the domain, the activity log or events", function () use ($key, $arrange, $extension, $failing) {
            Http::preventStrayRequests();

            if ($arrange !== null) {
                $arrange();
            }

            $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced()->create(['registrar_key' => $key]);
            $domain = Domain::factory()->pending()->create(['tld_id' => $tld->id, 'registrar_key' => $key]);
            Event::fake([RegistrarActionFailed::class]);
            $configured = RegistrarDriverContract::config(app(RegistrarDriverRegistry::class)->get($key));
            RegistrarAccount::query()->updateOrCreate(['key' => $key], ['is_active' => true, 'test_mode' => true, 'settings' => $configured->settings]);
            $echo = 'Rejected: '.http_build_query(RegistrarDriverContract::SECRETS).' with '.implode(' ', $configured->secrets());
            $fail = $failing ?? fn (string $key, Throwable $error) => FakeRegistrar::install($key)->will('register', $error);

            app(SubmitToRegistrar::class)->handle($domain);
            $rejected = Domain::factory()->pending()->create(['tld_id' => $domain->tld_id, 'registrar_key' => $key]);
            $fail($key, new RegistrarException($echo));
            app(SubmitToRegistrar::class)->handle($rejected);
            $lost = Domain::factory()->pending()->create(['tld_id' => $domain->tld_id, 'registrar_key' => $key]);
            $fail($key, new RuntimeException($echo));
            app(SubmitToRegistrar::class)->handle($lost);

            $ids = [$domain->id, $rejected->id, $lost->id];
            $seen = (string) json_encode([
                RegistrarCommandLog::query()->whereIn('domain_id', $ids)->get(['request', 'response', 'error']),
                Domain::query()->whereIn('id', $ids)->pluck('last_error'),
                ActivityLog::query()->where('subject_type', (new Domain)->getMorphClass())->whereIn('subject_id', $ids)->get(['description', 'properties']),
                Event::dispatched(RegistrarActionFailed::class)->map(fn (array $event): array => get_object_vars($event[0])),
            ]);

            expect(Event::dispatched(RegistrarActionFailed::class))->not->toBeEmpty()
                ->and($lost->fresh()->needs_review)->toBeTrue();

            foreach ([...array_values(RegistrarDriverContract::SECRETS), ...$configured->secrets()] as $secret) {
                expect($seen)->not->toContain($secret);
            }
        });

        test("{$key} registrar: what a driver says on success and in a connection test reaches staff masked", function () use ($key, $extension) {
            $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced()->create(['registrar_key' => $key]);
            $configured = RegistrarDriverContract::config(app(RegistrarDriverRegistry::class)->get($key));
            RegistrarAccount::query()->updateOrCreate(['key' => $key], ['is_active' => true, 'test_mode' => true, 'settings' => $configured->settings]);
            $echo = 'Accepted: '.implode(' ', [...$configured->secrets(), RegistrarDriverContract::SECRETS['epp_code']]);
            $fake = FakeRegistrar::install($key)->will('transfer', new RegistrarResult('pending', message: $echo))->will('test_connection', new ConnectionCheck(false, 'Refused: '.implode(' ', $configured->secrets())));
            $domain = Domain::factory()->pending('transfer')->create(['tld_id' => $tld->id, 'registrar_key' => $key, 'epp_code' => RegistrarDriverContract::SECRETS['epp_code']]);
            actingAsStaff(['domains.registrars.manage']);
            confirmPassword();

            $submitted = app(SubmitToRegistrar::class)->handle($domain);
            $this->post(route('admin.domains.registrars.test', $key));

            $seen = (string) json_encode([
                $submitted->message,
                session('inertia.flash_data.toast'),
                RegistrarCommandLog::query()->get(['request', 'response', 'error']),
                ActivityLog::query()->get(['description', 'properties']),
            ]);

            expect($fake->commands())->toContain('transfer', 'test_connection');

            foreach ([...$configured->secrets(), RegistrarDriverContract::SECRETS['epp_code']] as $secret) {
                expect($seen)->not->toContain($secret);
            }
        });

        test("{$key} registrar: the host sends contacts in the kernel shape", function () use ($key, $extension) {
            $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced()->create(['registrar_key' => $key]);
            $domain = Domain::factory()->pending()->create(['tld_id' => $tld->id, 'registrar_key' => $key]);
            app(BuildDomainContacts::class)->forNewDomain($domain, $domain->client);

            $contacts = app(BuildDomainContacts::class)->contactSet($domain->fresh())->toArray();

            expect(array_keys($contacts))->toEqualCanonicalizing(ContactSet::ROLES);

            foreach ($contacts as $role => $fields) {
                expect(array_keys($fields))->toEqualCanonicalizing(ContactSet::FIELDS, "{$role} has other keys than ContactSet::FIELDS");
            }
        });
    }

    /**
     * The calls of every interface the driver implements, as the host makes them, against the contract account.
     *
     * @return array<class-string, array<string, array{0: string, 1: Closure(): mixed}>>
     */
    public static function runs(RegistrarDriver $driver): array
    {
        $config = self::config($driver);
        $runs = [];

        foreach (self::calls() as $interface => $calls) {
            if ($driver instanceof $interface) {
                $runs[$interface] = $calls($driver, $config);
            }
        }

        return $runs;
    }

    /**
     * Run one call: it answers with $expected (`list` for a list of AvailabilityResult, `fields` for declared SettingFields, `answers` for field-key messages; a write is completed or
     * pending) or a RegistrarException, never the host's CapabilityNotSupported guard and never another error.
     *
     * @param  Closure(): mixed  $run
     */
    public static function assertOutcome(string $name, string $expected, Closure $run): void
    {
        try {
            $result = $run();
        } catch (RegistrarException $rejected) {
            expect($rejected)->not->toBeInstanceOf(CapabilityNotSupported::class, "{$name} threw the host's capability guard");

            return;
        }

        if ($expected === 'fields') {
            expect($result)->toBeList()->each->toBeInstanceOf(SettingField::class);

            foreach ($result as $field) {
                expect($field->isSecret())->toBeFalse("{$name}: {$field->key} is secret")
                    ->and($field->optionsSource)->toBeNull("{$name}: {$field->key} takes remote options");
            }

            expect(array_column(array_map(fn (SettingField $field): array => ['key' => $field->key], $result), 'key'))->each->toBeString();

            return;
        }

        if ($expected === 'answers') {
            expect($result)->toBeArray();

            foreach ($result as $key => $message) {
                expect($key)->toBeString()->and($message)->toBeString();
            }

            return;
        }

        if ($expected === 'list') {
            expect($result)->toBeList()->each->toBeInstanceOf(AvailabilityResult::class);

            return;
        }

        expect($result)->toBeInstanceOf($expected, "{$name} returned ".get_debug_type($result));

        if ($result instanceof RegistrarResult) {
            expect($result->status)->toBeIn(['completed', 'pending'], "{$name} answered {$result->status}");
        }
    }

    /**
     * One entry per interface: call name => [expected result, call]. A new capability in RegistrarDriverRegistry
     * needs an entry here; the completeness test in tests/Extensions/AcmePanelWithoutServicesTest holds the two lists together.
     *
     * @return array<class-string, Closure(mixed, RegistrarConfig): array<string, array{0: string, 1: Closure(): mixed}>>
     */
    public static function calls(): array
    {
        $domain = self::domain();
        $request = self::request($domain);
        $since = CarbonImmutable::now()->subHour();

        return [
            RegistrarDriver::class => fn (RegistrarDriver $driver, RegistrarConfig $config): array => [
                'register' => [RegistrarResult::class, fn (): mixed => $driver->register($config, $request)],
                'renew' => [RegistrarResult::class, fn (): mixed => $driver->renew($config, $domain, 1)],
                'info' => [DomainInfo::class, fn (): mixed => $driver->info($config, $domain)],
            ],
            ChecksAvailability::class => fn (ChecksAvailability $driver, RegistrarConfig $config): array => ['availability' => ['list', fn (): mixed => $driver->checkAvailability($config, $domain->sld, [$domain->tld])]],
            DeclaresTldAttributes::class => fn (DeclaresTldAttributes $driver, RegistrarConfig $config): array => [
                'tld_attributes' => ['fields', fn (): mixed => array_merge(...array_map(fn (string $tld): array => $driver->tldAttributeFields($tld), ['.com', '.us', '.com.au']))],
                ...($driver instanceof ValidatesTldAttributes ? ['tld_attributes_validate' => ['answers', fn (): mixed => $driver->validateTldAttributes('.com.au', ['id-type' => 'ABN', 'id' => '1'])]] : []),
            ],
            SuggestsNames::class => fn (SuggestsNames $driver, RegistrarConfig $config): array => ['suggestions' => ['list', fn (): mixed => $driver->suggest($config, $domain->sld, [$domain->tld], 5)]],
            TransfersDomains::class => fn (TransfersDomains $driver, RegistrarConfig $config): array => [
                'transfer' => [RegistrarResult::class, fn (): mixed => $driver->transfer($config, $request)],
                'transfer_sync' => [TransferSyncResult::class, fn (): mixed => $driver->transferSync($config, $domain)],
            ],
            RestoresDomains::class => fn (RestoresDomains $driver, RegistrarConfig $config): array => ['restore' => [RegistrarResult::class, fn (): mixed => $driver->restore($config, $domain, 1)]],
            ManagesNameservers::class => fn (ManagesNameservers $driver, RegistrarConfig $config): array => ['nameservers' => [RegistrarResult::class, fn (): mixed => $driver->setNameservers($config, $domain, ['ns1.contract.test', 'ns2.contract.test'])]],
            ManagesLock::class => fn (ManagesLock $driver, RegistrarConfig $config): array => ['lock' => [RegistrarResult::class, fn (): mixed => $driver->setLock($config, $domain, true)]],
            ManagesContacts::class => fn (ManagesContacts $driver, RegistrarConfig $config): array => [
                'get_contacts' => [ContactSet::class, fn (): mixed => $driver->getContacts($config, $domain)],
                'contacts' => [RegistrarResult::class, fn (): mixed => $driver->setContacts($config, $domain, $request->contacts)],
            ],
            ProvidesEppCode::class => fn (ProvidesEppCode $driver, RegistrarConfig $config): array => ['epp' => [EppCodeResult::class, fn (): mixed => $driver->getEppCode($config, $domain)]],
            ManagesIdProtection::class => fn (ManagesIdProtection $driver, RegistrarConfig $config): array => ['id_protection' => [RegistrarResult::class, fn (): mixed => $driver->setIdProtection($config, $domain, true)]],
            SyncsDomains::class => fn (SyncsDomains $driver, RegistrarConfig $config): array => ['sync' => [SyncResult::class, fn (): mixed => $driver->sync($config, $domain)]],
            ReconcilesSubmissions::class => fn (ReconcilesSubmissions $driver, RegistrarConfig $config): array => [
                'reconcile_register' => [SubmissionLookup::class, fn (): mixed => $driver->findSubmission($config, $domain, 'register', $since)],
                'reconcile_renew' => [SubmissionLookup::class, fn (): mixed => $driver->findSubmission($config, $domain, 'renew', $since)],
            ],
            LinksExistingOrders::class => fn (LinksExistingOrders $driver, RegistrarConfig $config): array => ['link_order' => [LinkedOrder::class, fn (): mixed => $driver->lookupOrder($config, $domain, '80000001')]],
            TestsRegistrarConnection::class => fn (TestsRegistrarConnection $driver, RegistrarConfig $config): array => ['test_connection' => [ConnectionCheck::class, fn (): mixed => $driver->testConnection($config)]],
        ];
    }

    /**
     * The account in test mode, with a recognisable value in every secret setting and defaults elsewhere.
     */
    public static function config(RegistrarDriver $driver): RegistrarConfig
    {
        $settings = [];
        $secretSettingKeys = [];

        foreach ($driver->settingsFields() as $field) {
            if ($field->isSecret()) {
                $settings[$field->key] = "contract-{$field->key}-value";
                $secretSettingKeys[] = $field->key;
            } elseif ($field->default !== null) {
                $settings[$field->key] = $field->default;
            }
        }

        return new RegistrarConfig($driver->key(), $settings, true, $secretSettingKeys);
    }

    public static function domain(): DomainRef
    {
        return new DomainRef(9001, 42, 'contract-test.com', 'contract-test', '.com', 'active', CarbonImmutable::today()->addMonths(2), 'contract', ['order_id' => 123], ['ns1.contract.test', 'ns2.contract.test'], true);
    }

    public static function request(DomainRef $domain): RegistrationRequest
    {
        // The kernel contact shape (ContactSet::ROLES and FIELDS), as the host sends it: optional fields may be null.
        $contact = [...array_fill_keys(ContactSet::FIELDS, null), 'first_name' => 'Asha', 'last_name' => 'Shrestha', 'email' => 'asha@contract.test', 'phone' => '01-5550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'postcode' => '44600', 'country' => 'NP'];

        return new RegistrationRequest($domain, 1, ['ns1.contract.test', 'ns2.contract.test'], new ContactSet(array_fill_keys(ContactSet::ROLES, $contact)), eppCode: self::SECRETS['epp_code']);
    }
}
