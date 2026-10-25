<?php

use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Support\DomainRegistrarLock;
use App\Support\Queues;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    config(['queue.default' => 'database']);
});

function probeRegistrarWorker(): void
{
    Artisan::call('queue:work', ['--once' => true, '--queue' => Queues::REGISTRAR, '--tries' => 3, '--sleep' => 0]);
}

test('a paid renewal picked up 31 minutes after dispatch still renews (no lock contention at all)', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    RenewDomain::dispatch($domain->id, 1, '2027-01-31');

    $this->travel(31)->minutes();
    probeRegistrarWorker();

    expect($this->registrar->commands())->toBe(['renew'])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2028-01-31');
});

test('a paid registration picked up 31 minutes after dispatch is still submitted', function () {
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    RegisterDomain::dispatch($domain->id);

    $this->travel(31)->minutes();
    probeRegistrarWorker();

    expect($this->registrar->commands())->toContain('register')
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('a duplicate renewal of the same cycle is refused while one waits for the lock', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $holder = DomainRegistrarLock::for($domain->id);
    $holder->get();
    RenewDomain::dispatch($domain->id, 1, '2027-01-31');
    probeRegistrarWorker(); // released

    RenewDomain::dispatch($domain->id, 1, '2027-01-31');

    expect(DB::table('jobs')->count())->toBe(1);
});

test('an expired holder cannot release the next holder\'s lock', function () {
    Cache::flush();
    $a = DomainRegistrarLock::for(9);
    expect($a->get())->toBeTrue();

    $this->travel(DomainRegistrarLock::TTL + 1)->seconds();
    $b = DomainRegistrarLock::for(9);
    expect($b->get())->toBeTrue();

    expect($a->release())->toBeFalse()
        ->and(DomainRegistrarLock::for(9)->get())->toBeFalse(); // still B's
});

test('staff Sync now on a busy domain shows the busy message', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'last_synced_at' => null]);
    DomainRegistrarLock::for($domain->id)->get();

    $this->post(route('admin.domains.command', [$domain, 'sync']))
        ->assertRedirect()
        ->assertSessionHasErrors(['registrar' => 'Another registrar action is running for this domain. Try again in a minute.']);

    expect($this->registrar->commands())->toBe([])->and($domain->fresh()->last_synced_at)->toBeNull();
});
