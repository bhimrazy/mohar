<?php

/*
 * Review-board probes for PR #164 (registrar prerequisites), adopted as regression tests. Each test names the
 * finding it covers; "fails at 508acbc4" marks a probe that reproduced a finding before its fix.
 */

use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Jobs\SyncRegistrars;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Queues;
use Illuminate\Foundation\DevCommands;
use Symfony\Component\Yaml\Yaml;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;

/** @return list<object> one instance of every registrar job */
function pr164RegistrarJobs(): array
{
    return [new RegisterDomain(1), new TransferDomain(1), new RenewDomain(1, 1, '2027-01-31'), new SyncRegistrars];
}

/** @return list<string> the queues a worker command reads */
function pr164Queues(string $command): array
{
    return preg_match('/--queue=([^\s]+)/', $command, $m) === 1 ? explode(',', $m[1]) : [Queues::DEFAULT];
}

// B1 — fails at 508acbc4: `composer dev` runs workers on provisioning,default / notifications,webhooks / automation
// only, so a paid registration, a transfer, a renewal and the hourly sync sit on `registrar` forever in local dev,
// the demo and product walk-throughs.
test('B1: the dev runner consumes every queue a registrar job is pushed on', function () {
    $read = collect(DevCommands::commands())
        ->pluck('command')
        ->filter(fn (string $command): bool => str_contains($command, 'queue:listen') || str_contains($command, 'queue:work'))
        ->flatMap(fn (string $command): array => pr164Queues($command))
        ->unique()
        ->all();

    foreach (pr164RegistrarJobs() as $job) {
        expect(in_array($job->queue, $read, true))->toBeTrue($job::class.' is pushed on a queue no dev worker reads');
    }
});

// Verifies the claim: production reads `registrar` with a timeout above every job's, a stop grace above that and a
// retry_after above both (ADR 0017 §3), in both colours.
test('registrar jobs fit the production worker that reads their queue', function () {
    $services = Yaml::parseFile(dirname(base_path()).'/deploy/compose.prod.yaml')['services'];
    $readers = collect($services)->filter(fn (array $service): bool => is_array($service['command'] ?? null)
        && in_array('queue:work', $service['command'], true)
        && in_array(Queues::REGISTRAR, pr164Queues(implode(' ', $service['command'])), true));

    expect($readers->keys()->all())->toEqualCanonicalizing(['worker-critical-blue', 'worker-critical-green']);

    foreach ($readers as $service) {
        $timeout = (int) str_replace('--timeout=', '', (string) collect($service['command'])->first(fn (string $arg): bool => str_starts_with($arg, '--timeout=')));
        $grace = (int) rtrim($service['stop_grace_period'], 's');

        foreach (pr164RegistrarJobs() as $job) {
            expect($job->queue)->toBe(Queues::REGISTRAR)
                ->and($job->timeout)->toBeLessThan($timeout)
                ->and($timeout)->toBeLessThan($grace)
                ->and($timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
                ->and($timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
        }
    }
});

// Verifies the claim "integer fields are validated and stored as numbers, select values are limited to their
// options" (no test in the PR; np_manual in #168 depends on it).
test('registrar settings: integers are validated and stored as numbers, selects keep to their options', function () {
    $fake = new class(new FakeRegistrar, 'probe') extends FakeRegistrarDriver
    {
        public function settingsFields(): array
        {
            return [
                new SettingField('api_key', 'API key', FieldType::Secret),
                new SettingField('sla_days', 'SLA', FieldType::Number, default: 4),
                new SettingField('mode', 'Mode', FieldType::Select, options: ['live' => 'Live', 'test' => 'Test']),
            ];
        }
    };
    app(RegistrarDriverRegistry::class)->register('probe', fn () => $fake);
    actingAsStaff(['domains.registrars.manage']);
    confirmPassword();
    $route = route('admin.domains.registrars.update', 'probe');

    $this->put($route, ['is_active' => true, 'test_mode' => false, 'settings' => ['sla_days' => 'abc', 'mode' => 'bogus']])
        ->assertSessionHasErrors(['settings.sla_days', 'settings.mode']);
    $this->put($route, ['is_active' => true, 'test_mode' => false, 'settings' => ['api_key' => 'k-1', 'sla_days' => '7', 'mode' => 'test']])
        ->assertSessionHasNoErrors();

    expect(RegistrarAccount::query()->where('key', 'probe')->sole()->settings)
        ->toBe(['api_key' => 'k-1', 'sla_days' => 7, 'mode' => 'test']);
});
