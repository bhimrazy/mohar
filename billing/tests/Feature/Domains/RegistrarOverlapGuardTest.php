<?php

use App\Data\Domains\Registrar\RegistrarResult;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Actions\SyncDomains;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Jobs\Middleware\OnePerDomain;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Support\DomainRegistrarLock;
use App\Support\Domains\RegistrarException;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    config(['queue.default' => 'database']);
});

/** Run one queued registrar job the way a worker does (real middleware, releases, tries and failures). */
function runRegistrarWorker(): void
{
    Artisan::call('queue:work', ['--once' => true, '--queue' => Queues::REGISTRAR, '--tries' => 3, '--sleep' => 0]);
}

/** Make every released job due now. */
function makeJobsDue(): void
{
    DB::table('jobs')->update(['available_at' => now()->subSecond()->timestamp]);
}

function pendingRegistration(object $test): Domain
{
    return Domain::factory()->pending()->create(['tld_id' => $test->com->id, 'registrar_key' => 'fake']);
}

test('a renewal dispatched while a registration runs waits instead of calling the registrar at the same time', function () {
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $seen = [];
    $this->registrar->will('register', function () use ($domain, &$seen) {
        RenewDomain::dispatch($domain->id, 1, '2027-01-31');
        runRegistrarWorker(); // another worker picking the renewal up while the registration is in flight
        $seen = $this->registrar->commands();

        return new RegistrarResult('completed', CarbonImmutable::today()->addYear());
    });
    RegisterDomain::dispatch($domain->id);

    runRegistrarWorker();

    expect($seen)->toBe(['register']) // the renewal had not reached the registrar
        ->and(DB::table('jobs')->count())->toBe(1) // released, not deleted
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('the blocked job runs exactly once after the holder finishes', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $holder = DomainRegistrarLock::for($domain->id);
    $holder->get();
    RenewDomain::dispatch($domain->id, 1, '2027-01-31');

    runRegistrarWorker();
    expect($this->registrar->commands())->toBe([])->and(DB::table('jobs')->count())->toBe(1);

    $holder->release();
    makeJobsDue();
    runRegistrarWorker();
    makeJobsDue();
    runRegistrarWorker();

    expect($this->registrar->commands())->toBe(['renew'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2028-01-31');
});

test('waiting for the lock never uses up the attempts of a paid job', function (string $type) {
    $domain = Domain::factory()->pending($type)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $holder = DomainRegistrarLock::for($domain->id);
    $holder->get();
    dispatch($type === 'transfer' ? new TransferDomain($domain->id) : new RegisterDomain($domain->id));

    foreach (range(1, 6) as $round) { // twice the old three tries
        runRegistrarWorker();
        makeJobsDue();
    }

    expect(DB::table('jobs')->value('attempts'))->toBeGreaterThanOrEqual(6)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $holder->release();
    runRegistrarWorker();

    expect(array_count_values($this->registrar->commands())[$type])->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
})->with(['registration' => ['register'], 'transfer' => ['transfer']]);

test('real errors still end the job after three, and a job that waits too long fails visibly instead of vanishing', function () {
    $this->registrar->will('register', new RegistrarException('registrar busy', retryable: true));
    $domain = pendingRegistration($this);
    RegisterDomain::dispatch($domain->id);

    foreach (range(1, 4) as $round) {
        runRegistrarWorker();
        makeJobsDue();
    }

    expect(DB::table('failed_jobs')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);

    DB::table('failed_jobs')->delete();
    $other = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $holder = DomainRegistrarLock::for($other->id);
    $holder->get();
    RenewDomain::dispatch($other->id, 1, '2027-01-31');
    $this->travel(31)->minutes();
    $holder->release();
    DomainRegistrarLock::for($other->id)->get();

    runRegistrarWorker();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->registrar->commands())->not->toContain('renew')
        ->and($other->fresh()->status)->toBe(DomainStatus::Active);
});

test('jobs of different domains do not block each other', function () {
    $busy = pendingRegistration($this);
    $other = pendingRegistration($this);
    DomainRegistrarLock::for($busy->id)->get();
    RegisterDomain::dispatch($other->id);

    runRegistrarWorker();

    expect(array_count_values($this->registrar->commands())['register'])->toBe(1)
        ->and($other->fresh()->status)->toBe(DomainStatus::Active);
});

test('a sync does not run against a domain a registrar job holds, and a bulk sync leaves it for the next run', function () {
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'last_synced_at' => null]);
    $holder = DomainRegistrarLock::for($domain->id);
    $holder->get();

    $single = app(SyncDomain::class)->handle($domain);
    $bulk = app(SyncDomains::class)->handle();

    expect($single['error'])->toBe(SyncDomain::BUSY)
        ->and($bulk)->toBe(['synced' => 0, 'changes' => 0, 'errors' => 0])
        ->and($this->registrar->commands())->toBe([])
        ->and($domain->fresh()->last_synced_at)->toBeNull();

    $holder->release();

    expect(app(SyncDomains::class)->handle()['synced'])->toBe(1)
        ->and(DomainRegistrarLock::for($domain->id)->get())->toBeTrue(); // the sync released it
});

test('every registrar job guards the domain with the same lock and holds it longer than its own timeout', function () {
    foreach ([new RegisterDomain(7), new TransferDomain(7), new RenewDomain(7, 1)] as $job) {
        $middleware = $job->middleware();

        expect($middleware)->toHaveCount(1)->and($middleware[0])->toBeInstanceOf(OnePerDomain::class)
            ->and($job->timeout)->toBeLessThan(DomainRegistrarLock::TTL)
            ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(10)->getTimestamp())
            ->and($job->tries ?? null)->toBeNull();
    }
});
