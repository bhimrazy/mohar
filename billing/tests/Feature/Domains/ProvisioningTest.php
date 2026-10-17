<?php

use App\Contracts\Domains\DomainRegistry;
use App\Data\Domains\DomainDraft;
use App\Events\Domains\DomainRegistered;
use App\Events\Domains\DomainStatusChanged;
use App\Events\Domains\DomainTransferInitiated;
use App\Events\Domains\RegistrarActionFailed;
use App\Modules\Core\Models\Client;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Exceptions\RegistrarTimeout;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarCommandLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
    $this->client = Client::factory()->create(['email' => 'owner@example.com', 'first_name' => 'Ada']);
    $this->registry = app(DomainRegistry::class);
});

function draft(Client $client, string $domain = 'example.com', string $action = 'register', ?string $epp = null, array $nameservers = []): DomainDraft
{
    return new DomainDraft($client->id, $domain, $action, 1, 'USD', 1400, 1200, null, $epp, $nameservers);
}

test('placing an order creates a pending domain without calling the registrar', function () {
    $id = $this->registry->createPending(draft($this->client, 'Example.COM', nameservers: ['NS1.Host.com', 'ns2.host.com']));

    $domain = Domain::query()->with('contacts')->findOrFail($id);
    expect($domain)
        ->name->toBe('example.com')
        ->sld->toBe('example')
        ->tld->toBe('.com')
        ->status->toBe(DomainStatus::Pending)
        ->registrar_key->toBe('fake')
        ->recurring_amount->toBe(1400)
        ->nameservers->toBe(['ns1.host.com', 'ns2.host.com'])
        ->and($domain->contacts->pluck('role')->sort()->values()->all())->toBe(['admin', 'billing', 'registrant', 'tech'])
        ->and($domain->contacts->firstWhere('role', 'registrant')->first_name)->toBe('Ada')
        ->and($this->registrar->calls)->toBe([]);
});

test('default nameservers are used when the order has none', function () {
    config(['domains.default_nameservers' => ['ns1.default.test', 'ns2.default.test']]);

    $domain = Domain::query()->findOrFail($this->registry->createPending(draft($this->client)));

    expect($domain->nameservers)->toBe(['ns1.default.test', 'ns2.default.test']);
});

test('pending domains are created with IDN names in punycode', function () {
    $domain = Domain::query()->findOrFail($this->registry->createPending(draft($this->client, 'bücher.com')));

    expect($domain->name)->toBe('xn--bcher-kva.com')->and($domain->name_unicode)->toBe('bücher.com');
});

test('a transfer requires an EPP code when the TLD demands one', function () {
    expect(fn () => $this->registry->createPending(draft($this->client, 'example.com', 'transfer')))
        ->toThrow(ValidationException::class);

    $id = $this->registry->createPending(draft($this->client, 'example.com', 'transfer', 'AUTH-123'));
    expect(Domain::query()->findOrFail($id)->epp_code)->toBe('AUTH-123');
});

test('a name already held here cannot be ordered again', function () {
    $this->registry->createPending(draft($this->client));

    $this->registry->createPending(draft(Client::factory()->create(), 'EXAMPLE.com'));
})->throws(ValidationException::class);

test('a name can be ordered again once the previous record is terminal', function () {
    Domain::factory()->named('example', $this->com)->status(DomainStatus::TransferredAway)->create();

    expect($this->registry->createPending(draft($this->client)))->toBeInt();
});

test('unsupported extensions are rejected', function () {
    $this->registry->createPending(draft($this->client, 'example.dev'));
})->throws(ValidationException::class);

test('a successful registration activates the domain and sets its dates', function () {
    Event::fake([DomainRegistered::class, DomainStatusChanged::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-17'));
    $this->registrar->will('register', new RegistrarResult('completed', CarbonImmutable::parse('2027-10-17')));
    $id = $this->registry->createPending(draft($this->client));

    $result = $this->registry->process($id);

    $domain = Domain::query()->findOrFail($id);
    expect($result->success)->toBeTrue()
        ->and($domain->status)->toBe(DomainStatus::Active)
        ->and($domain->expiry_date->toDateString())->toBe('2027-10-17')
        ->and($domain->next_due_date->toDateString())->toBe('2027-10-17')
        ->and($domain->next_invoice_date->toDateString())->toBe('2027-09-17')
        ->and($domain->registration_date->toDateString())->toBe('2026-10-17')
        ->and($domain->registrar_lock)->toBeTrue()
        ->and($this->registrar->commands())->toBe(['register', 'lock']);
    Event::assertDispatched(DomainRegistered::class, fn (DomainRegistered $event) => $event->domainId === $id);
    Event::assertDispatched(DomainStatusChanged::class, fn (DomainStatusChanged $event) => $event->from === 'pending' && $event->to === 'active');
    $this->assertDatabaseHas('registrar_command_log', ['domain_id' => $id, 'command' => 'register', 'success' => true]);
});

test('a registrar timeout leaves the domain pending registration and never re-registers', function () {
    $this->registrar->will('register', new RegistrarTimeout);
    $id = $this->registry->createPending(draft($this->client));

    (new RegisterDomain($id))->handle(app(SubmitToRegistrar::class));
    (new RegisterDomain($id))->handle(app(SubmitToRegistrar::class));

    expect(Domain::query()->findOrFail($id)->status)->toBe(DomainStatus::PendingRegistration)
        ->and($this->registrar->commands())->toBe(['register']);
});

test('an explicit registrar error keeps the domain pending and alerts staff', function () {
    Event::fake([RegistrarActionFailed::class]);
    $this->registrar->will('register', new RegistrarException('Name blocked by registry'));
    $id = $this->registry->createPending(draft($this->client));

    $result = $this->registry->process($id);

    expect($result->success)->toBeFalse()
        ->and(Domain::query()->findOrFail($id))->status->toBe(DomainStatus::Pending)->last_error->toBe('Name blocked by registry');
    Event::assertDispatched(RegistrarActionFailed::class, fn (RegistrarActionFailed $event) => $event->action === 'register' && $event->domainId === $id);
    $this->assertDatabaseHas('registrar_command_log', ['domain_id' => $id, 'command' => 'register', 'success' => false, 'error' => 'Name blocked by registry']);
});

test('retryable registrar errors are re-thrown for the queue to retry', function () {
    $this->registrar->will('register', new RegistrarException('Connection refused', retryable: true));
    $id = $this->registry->createPending(draft($this->client));

    (new RegisterDomain($id))->handle(app(SubmitToRegistrar::class));
})->throws(RegistrarException::class, 'Connection refused');

test('a resubmission is reconciled with the registrar instead of registering twice', function () {
    $id = $this->registry->createPending(draft($this->client));
    Domain::query()->whereKey($id)->update(['registrar_meta' => json_encode(['submission' => now()->subHour()->toIso8601String()])]);
    $this->registrar->will('info', new DomainInfo('active', expiryDate: CarbonImmutable::parse('2027-10-17')));

    $this->registry->process($id);

    expect(Domain::query()->findOrFail($id)->status)->toBe(DomainStatus::Active)
        ->and($this->registrar->commands())->not->toContain('register');
});

test('an accepted transfer moves to pending transfer', function () {
    Event::fake([DomainTransferInitiated::class]);
    $id = $this->registry->createPending(draft($this->client, 'example.com', 'transfer', 'AUTH-1'));

    $this->registry->process($id);

    expect(Domain::query()->findOrFail($id)->status)->toBe(DomainStatus::PendingTransfer);
    Event::assertDispatched(DomainTransferInitiated::class, fn (DomainTransferInitiated $event) => $event->domainId === $id);
    expect(RegistrarCommandLog::query()->where('command', 'transfer')->value('request'))
        ->toHaveKey('epp_code', '[redacted]');
});

test('processing a domain that is not pending does nothing', function () {
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    expect($this->registry->process($domain->id)->success)->toBeFalse()
        ->and($this->registrar->calls)->toBe([]);
});

test('cancelling an order cancels or flags the pending domain', function () {
    $first = $this->registry->createPending(draft($this->client, 'one.com'));
    $second = $this->registry->createPending(draft($this->client, 'two.com'));

    $this->registry->cancelPending($first);
    $this->registry->cancelPending($second, 'fraud');

    expect(Domain::query()->findOrFail($first)->status)->toBe(DomainStatus::Cancelled)
        ->and(Domain::query()->findOrFail($second)->status)->toBe(DomainStatus::Fraud)
        ->and(Domain::query()->findOrFail($first)->active_name)->toBeNull();
});
