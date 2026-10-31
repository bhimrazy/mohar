<?php

use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Support\Domains\RegistrarException;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

/**
 * ADR 0030 A1: a disabled registrar account runs only the read-only settlement commands; every command that changes
 * something at the registrar is still refused before the driver is called.
 */
beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    $this->domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake'])->fresh();
});

test('a disabled account refuses every command that changes the registrar', function (string $capability, string $interface, Closure $call) {
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false]);

    expect(fn () => app(RegistrarGateway::class)->forDomain($this->domain, $capability, $interface, $call))
        ->toThrow(RegistrarException::class, 'disabled')
        ->and($this->registrar->calls)->toBe([]);
})->with([
    'renew' => ['renew', RegistrarDriver::class, fn (RegistrarDriver $d, RegistrarConfig $c) => $d->renew($c, test()->domain->toRef(), 1)],
    'nameservers' => ['nameservers', ManagesNameservers::class, fn (ManagesNameservers $d, RegistrarConfig $c) => $d->setNameservers($c, test()->domain->toRef(), ['ns1.example.com', 'ns2.example.com'])],
    'lock' => ['lock', ManagesLock::class, fn (ManagesLock $d, RegistrarConfig $c) => $d->setLock($c, test()->domain->toRef(), true)],
    'epp' => ['epp', ProvidesEppCode::class, fn (ProvidesEppCode $d, RegistrarConfig $c) => $d->getEppCode($c, test()->domain->toRef())],
]);

test('a disabled account still runs sync, transfer sync and info', function () {
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false]);
    $gateway = app(RegistrarGateway::class);

    $gateway->forDomain($this->domain, 'sync', SyncsDomains::class, fn (SyncsDomains $d, RegistrarConfig $c) => $d->sync($c, $this->domain->toRef()));
    $gateway->forDomain($this->domain, 'transfer_sync', TransfersDomains::class, fn (TransfersDomains $d, RegistrarConfig $c) => $d->transferSync($c, $this->domain->toRef()));
    $gateway->forDomain($this->domain, 'info', RegistrarDriver::class, fn (RegistrarDriver $d, RegistrarConfig $c) => $d->info($c, $this->domain->toRef()));

    expect($this->registrar->commands())->toBe(['sync', 'transfer_sync', 'info']);
});
