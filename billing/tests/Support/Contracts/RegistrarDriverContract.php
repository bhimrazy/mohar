<?php

namespace Tests\Support\Contracts;

use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Exceptions\CapabilityNotSupported;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Support\Integrations\Redactor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Http;

/**
 * The contract every registrar driver passes (registrars.md §7). Call it from a test file, once per driver key:
 *
 *   RegistrarDriverContract::verify('resellerclub', arrange: fn () => Http::fake([...]));
 *
 * `arrange` runs before each check and fakes the remote system so calls succeed; stray HTTP fails the test. Checks:
 * - identity, capabilities from Registrar::CAPABILITIES, typed settings (credential-like keys are secrets with no
 *   default);
 * - every method outside the driver's capabilities throws CapabilityNotSupported, so the host can hide it;
 * - every supported call answers with exactly one outcome: its documented result type or a RegistrarException
 *   (rejected / retryable / RegistrarTimeout), never another error, and register is completed or pending;
 * - findSubmission (capability `reconcile`) answers a SubmissionLookup for register and renew;
 * - registrar credentials in a request (`api-key`, `passwd`, `signature`, `domsecret`, auth and EPP codes) and in
 *   an error message never reach registrar_command_log (C5).
 * Response-mapping scenarios of API drivers (timeouts, refused connections, fixtures) belong in the driver's own
 * tests.
 */
final class RegistrarDriverContract
{
    /** Credential values a driver might echo back; none may be stored in the command log. */
    public const SECRETS = [
        'api-key' => 'contract-api-key-1', 'passwd' => 'contract-passwd-2', 'signature' => 'contract-signature-3',
        'domsecret' => 'contract-domsecret-4', 'auth-code' => 'contract-auth-code-5', 'epp_code' => 'contract-epp-6',
    ];

    public static function verify(string $key, ?Closure $arrange = null, string $extension = '.com'): void
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

            $calls = RegistrarDriverContract::calls($domain);
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
            $types = [
                'register' => RegistrarResult::class, 'transfer' => RegistrarResult::class, 'renew' => RegistrarResult::class,
                'info' => DomainInfo::class, 'nameservers' => RegistrarResult::class, 'contacts' => RegistrarResult::class,
                'sync' => SyncResult::class, 'reconcile' => SubmissionLookup::class,
            ];

            foreach (RegistrarDriverContract::calls($domain) as $capability => $call) {
                if (! in_array($capability, $driver->capabilities(), true) || ! isset($types[$capability])) {
                    continue;
                }

                try {
                    $result = $call($driver);
                } catch (RegistrarException $rejected) {
                    expect($rejected)->not->toBeInstanceOf(CapabilityNotSupported::class);

                    continue;
                }

                expect($result)->toBeInstanceOf($types[$capability]);

                if ($capability === 'register') {
                    expect($result->status)->toBeIn(['completed', 'pending']);
                }
            }
        });

        test("{$key} registrar: credentials in requests and errors never reach the command log", function () use ($key, $setUp) {
            $domain = $setUp();
            $gateway = app(RegistrarGateway::class);
            $echo = http_build_query(RegistrarDriverContract::SECRETS);

            $gateway->call($key, 'register', fn (Registrar $driver): RegistrarResult => $driver->register(RegistrarDriverContract::request($domain)), $domain, [...RegistrarDriverContract::SECRETS, 'raw' => $echo]);

            try {
                $gateway->call($key, 'register', fn (): never => throw new RegistrarException("Rejected: {$echo}"), $domain, ['nested' => RegistrarDriverContract::SECRETS]);
            } catch (RegistrarException) {
                // Expected: only the log matters here.
            }

            $log = RegistrarCommandLog::query()->where('domain_id', $domain->id)->get(['request', 'response', 'error'])->toJson();

            expect(RegistrarCommandLog::query()->where('domain_id', $domain->id)->count())->toBe(2);

            foreach (RegistrarDriverContract::SECRETS as $secret) {
                expect($log)->not->toContain($secret);
            }
        });
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
