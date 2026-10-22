<?php

namespace Tests\Support\Contracts;

use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Exceptions\CapabilityNotSupported;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Support\Integrations\Redactor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Fakes\Domains\FakeRegistrar;
use Throwable;

/**
 * The Domains module's registrar SPI contract until Domains moves onto the kernel contracts (ADR 0009 PR C2), when
 * this file is removed for RegistrarDriverContract. The contract every registrar driver passes (registrars.md §7). Call it from a test file, once per driver key:
 *
 *   ModuleRegistrarDriverContract::verify('resellerclub', arrange: fn () => Http::fake([...]));
 *
 * `arrange` runs before each check and fakes the remote system so calls succeed; stray HTTP fails the test. Checks:
 * - identity, capabilities from Registrar::CAPABILITIES, typed settings (credential-like keys are secrets with no
 *   default);
 * - every method outside the driver's capabilities throws CapabilityNotSupported, so the host can hide it;
 * - every supported call answers with exactly one outcome: its documented result type or a RegistrarException
 *   (rejected / retryable / RegistrarTimeout), never another error, and register is completed or pending;
 * - findSubmission (capability `reconcile`) answers a SubmissionLookup for register and renew;
 * - with the account configured with known credentials, neither they nor credential-named pairs (`api-key`,
 *   `passwd`, `signature`, `domsecret`, auth and EPP codes) reach registrar_command_log, the domain's last_error, the
 *   activity log or RegistrarActionFailed (C5): on the real driver's submit path, and on a rejected and a lost
 *   register whose error text echoes them. `failing` makes the real driver fail that way (an API driver fakes its
 *   provider's error body); by default a scriptable driver takes its place for the failure.
 * Response-mapping scenarios of API drivers (timeouts, refused connections, fixtures) belong in the driver's own
 * tests.
 */
final class ModuleRegistrarDriverContract
{
    /** Credential values a driver might echo back; none may be stored in the command log. */
    public const SECRETS = [
        'api-key' => 'contract-api-key-1', 'passwd' => 'contract-passwd-2', 'signature' => 'contract-signature-3',
        'domsecret' => 'contract-domsecret-4', 'auth-code' => 'contract-auth-code-5', 'epp_code' => 'contract-epp-6',
    ];

    /**
     * @param  (Closure(): void)|null  $arrange
     * @param  (Closure(string, Throwable): void)|null  $failing  make the driver's next register fail with this error
     */
    public static function verify(string $key, ?Closure $arrange = null, string $extension = '.com', ?Closure $failing = null): void
    {
        $setUp = function () use ($key, $arrange, $extension): Domain {
            Http::preventStrayRequests();

            if ($arrange !== null) {
                $arrange();
            }

            $tld = Tld::query()->where('extension', $extension)->first() ?? Tld::factory()->extension($extension)->priced()->create(['registrar_key' => $key]);

            return Domain::factory()->pending()->create(['tld_id' => $tld->id, 'registrar_key' => $key]);
        };

        test("{$key} registrar: identity, capabilities and typed settings", function () use ($key, $setUp) {
            $setUp();
            $registrars = app(RegistrarManager::class);
            $driver = $registrars->driver($key);

            expect($driver->name())->not->toBe('')
                ->and(array_diff($driver->capabilities(), Registrar::CAPABILITIES))->toBe([])
                ->and($driver->capabilities())->toContain('register');

            foreach ($registrars->fields($key) as $field) {
                if (app(Redactor::class)->isSensitiveKey($field->key)) {
                    expect($field->isSecret())->toBeTrue("{$field->key} reads like a credential but is not a secret field");
                }

                if ($field->isSecret()) {
                    expect($field->default)->toBeNull();
                }
            }
        });

        test("{$key} registrar: calls outside its capabilities throw CapabilityNotSupported", function () use ($key, $setUp) {
            $domain = $setUp();
            $driver = app(RegistrarManager::class)->driver($key);

            $calls = ModuleRegistrarDriverContract::calls($domain);
            expect(array_keys($calls))->toEqualCanonicalizing(Registrar::CAPABILITIES);

            foreach ($calls as $capability => $call) {
                if (! in_array($capability, $driver->capabilities(), true)) {
                    expect(fn () => $call($driver))->toThrow(CapabilityNotSupported::class);
                }
            }
        });

        test("{$key} registrar: supported calls answer with one documented outcome", function () use ($key, $setUp) {
            $domain = $setUp();
            $driver = app(RegistrarManager::class)->driver($key);
            foreach (ModuleRegistrarDriverContract::calls($domain) as $capability => $call) {
                if (! in_array($capability, $driver->capabilities(), true)) {
                    continue;
                }

                try {
                    $result = $call($driver);
                } catch (RegistrarException $rejected) {
                    expect($rejected)->not->toBeInstanceOf(CapabilityNotSupported::class);

                    continue;
                }

                if ($capability === 'register') {
                    expect($result->status)->toBeIn(['completed', 'pending']);
                }
            }
        });

        test("{$key} registrar: configured credentials never reach the command log, the domain, the activity log or events", function () use ($key, $setUp, $failing) {
            $domain = $setUp();
            Event::fake([RegistrarActionFailed::class]);
            $configured = ModuleRegistrarDriverContract::configureSecrets($key);
            $echo = 'Rejected: '.http_build_query(ModuleRegistrarDriverContract::SECRETS).' with '.implode(' ', $configured);
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

            foreach ([...array_values(ModuleRegistrarDriverContract::SECRETS), ...$configured] as $secret) {
                expect($seen)->not->toContain($secret);
            }
        });
    }

    /**
     * Configure the registrar account with a known value in every secret setting (and defaults elsewhere).
     *
     * @return list<string> the configured secret values
     */
    public static function configureSecrets(string $key): array
    {
        $settings = [];
        $secrets = [];

        foreach (app(RegistrarManager::class)->fields($key) as $field) {
            if ($field->isSecret()) {
                $settings[$field->key] = $secrets[] = "contract-{$field->key}-value";
            } elseif ($field->default !== null) {
                $settings[$field->key] = $field->default;
            }
        }

        RegistrarAccount::query()->updateOrCreate(['key' => $key], ['is_active' => true, 'test_mode' => true, 'settings' => $settings]);

        return $secrets;
    }

    /**
     * One call per capability, as the host makes them.
     *
     * @return array<string, Closure(Registrar): mixed>
     */
    public static function calls(Domain $domain): array
    {
        $request = self::request($domain);

        return [
            'availability' => fn (Registrar $driver): mixed => $driver->checkAvailability($domain->sld, [$domain->tld]),
            'suggestions' => fn (Registrar $driver): mixed => $driver->suggest($domain->sld, [$domain->tld], 5),
            'register' => fn (Registrar $driver): mixed => $driver->register($request),
            'transfer' => fn (Registrar $driver): mixed => $driver->transfer($request),
            'info' => fn (Registrar $driver): mixed => $driver->info($domain),
            'nameservers' => fn (Registrar $driver): mixed => $driver->setNameservers($domain, ['ns1.contract.test', 'ns2.contract.test']),
            'contacts' => fn (Registrar $driver): mixed => $driver->setContacts($domain, $request->contacts),
            'sync' => fn (Registrar $driver): mixed => $driver->sync($domain),
            'reconcile' => fn (Registrar $driver): mixed => [$driver->findSubmission($domain, 'renew', CarbonImmutable::now()->subHour()), $driver->findSubmission($domain, 'register', CarbonImmutable::now()->subHour())][1],
            'renew' => fn (Registrar $driver): mixed => $driver->renew($domain, 1),
            'restore' => fn (Registrar $driver): mixed => $driver->renew($domain, 1, restore: true),
            'lock' => fn (Registrar $driver): mixed => $driver->setLock($domain, true),
            'id_protection' => fn (Registrar $driver): mixed => $driver->setIdProtection($domain, true),
            'epp' => fn (Registrar $driver): mixed => $driver->getEppCode($domain),
            'transfer_sync' => fn (Registrar $driver): mixed => $driver->transferSync($domain),
        ];
    }

    public static function request(Domain $domain): RegistrationRequest
    {
        $contact = ['first_name' => 'Asha', 'last_name' => 'Shrestha', 'email' => 'asha@contract.test', 'phone' => '+977.15550100', 'address_1' => '1 Durbar Marg', 'city' => 'Kathmandu', 'postcode' => '44600', 'country' => 'NP'];

        return new RegistrationRequest($domain, 1, ['ns1.contract.test', 'ns2.contract.test'], new ContactSet(['registrant' => $contact, 'admin' => $contact]));
    }
}
