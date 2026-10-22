<?php

/*
 * Review-board probes for the registrar-contracts stack (#262 C1, #264 C2, #265 C3). Each test names the finding it
 * covers; "fails at f989ac37" marks a probe that reproduced a finding at the reviewed head.
 */

use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Integrations\ConnectionCheck;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Drivers\Registrars\LogRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Models\Tld;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

/** A driver that echoes what it was given: the account key in its connection test, the EPP code in its answers. */
function pr262EchoingDriver(string $mode = 'message'): LogRegistrar
{
    return new class($mode) extends LogRegistrar
    {
        public function __construct(private string $mode) {}

        public function testConnection(RegistrarConfig $config): ConnectionCheck
        {
            if ($this->mode === 'throw') {
                throw new RegistrarException('Login refused for key '.$config->setting('api_key').'.');
            }

            return new ConnectionCheck(false, 'Login refused for key '.$config->setting('api_key').'.');
        }

        public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
        {
            if ($this->mode === 'throw') {
                throw new RegistrarException("The losing registrar refused auth code {$request->eppCode}.");
            }

            return new RegistrarResult('pending', message: "Transfer started with auth code {$request->eppCode}.");
        }
    };
}

// #264 blocking: the connection test bypasses RegistrarGateway, so its text reaches the Inertia flash unmasked.
// fails at f989ac37
test('probe: a connection test that echoes the account key never shows it to staff', function (string $mode) {
    app(RegistrarDriverRegistry::class)->register('log', fn () => pr262EchoingDriver($mode), replace: true);
    RegistrarAccount::query()->create(['key' => 'log', 'is_active' => true, 'settings' => ['api_key' => 'live-secret-key-77']]);
    actingAsStaff(['domains.registrars.manage']);
    confirmPassword();

    $response = $this->post(route('admin.domains.registrars.test', 'log'));

    $response->assertRedirect();
    expect(json_encode(session()->all()))->not->toContain('live-secret-key-77');
})->with(['answer' => 'message', 'exception' => 'throw']);

// #262/#264 blocking: the EPP code of a transfer is not in the host's redaction set, so a driver that echoes it puts
// the code in registrar_command_log, last_error, the activity log and RegistrarActionFailed. fails at f989ac37
test('probe: a transfer whose driver echoes the EPP code never stores the code', function (string $mode) {
    Http::preventStrayRequests();
    Event::fake([RegistrarActionFailed::class]);
    app(RegistrarDriverRegistry::class)->register('log', fn () => pr262EchoingDriver($mode), replace: true);
    $tld = DomainFixtures::com('log');
    $domain = Domain::factory()->pending('transfer')->create(['tld_id' => $tld->id, 'registrar_key' => 'log', 'epp_code' => 'Zq8-Secret-EPP-41']);

    app(SubmitToRegistrar::class)->handle($domain);

    $seen = (string) json_encode([
        RegistrarCommandLog::query()->where('domain_id', $domain->id)->get(['request', 'response', 'error']),
        $domain->fresh()->last_error,
        ActivityLog::query()->where('subject_id', $domain->id)->get(['description', 'properties']),
        Event::dispatched(RegistrarActionFailed::class)->map(fn (array $event): array => get_object_vars($event[0])),
    ]);

    expect($seen)->not->toContain('Zq8-Secret-EPP-41');
})->with(['answer' => 'message', 'exception' => 'throw']);

// Brief: the test fake's eval stays in test support. Passes at f989ac37; adopt as an arch rule.
arch('probe: nothing under app/ uses eval')
    ->expect('App')
    ->not->toUse(['eval']);

// .np (#199): searching a self-registered extension never reaches a registrar driver. Passes at f989ac37.
test('probe: a self-registered TLD search calls no registrar driver', function () {
    Http::preventStrayRequests();
    Currency::factory()->create();
    $fake = FakeRegistrar::install('log');
    config(['domains.lookup_registrar' => 'log']);
    Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'log']);

    $this->getJson(route('domains.lookup', ['q' => 'asha.com.np']))->assertOk()->assertJsonPath('results.0.status', 'self_registered');

    expect($fake->commands())->toBe([])
        ->and(RegistrarCommandLog::query()->count())->toBe(0);
});
