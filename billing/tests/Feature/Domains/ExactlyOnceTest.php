<?php

use App\Contracts\Domains\DomainRegistry;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentReceived;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->com = DomainFixtures::com();
});

test('a submission racing another one for the same domain is refused while in flight', function () {
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $registry = app(DomainRegistry::class);
    $nested = null;
    $this->registrar->will('register', function () use ($registry, $domain, &$nested) {
        $nested = $registry->process($domain->id);

        return new RegistrarResult('completed', CarbonImmutable::today()->addYear());
    });

    $first = $registry->process($domain->id);

    expect($first->success)->toBeTrue()
        ->and($nested->success)->toBeFalse()
        ->and($nested->message)->toContain('already in progress')
        ->and(array_count_values($this->registrar->commands())['register'])->toBe(1)
        ->and($domain->fresh()->status)->toBe(DomainStatus::Active);
});

test('an unexpected registrar error on register is an unknown outcome, never retried', function (string $type, DomainStatus $parked) {
    $this->registrar->will($type, new RuntimeException('read timeout after request was sent'));
    $domain = Domain::factory()->pending($type)->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $result = app(SubmitToRegistrar::class)->handle($domain);
    app(SubmitToRegistrar::class)->handle($domain->fresh());

    expect($result->success)->toBeFalse()
        ->and($domain->fresh())->status->toBe($parked)->needs_review->toBeTrue()
        ->and(array_count_values($this->registrar->commands())[$type])->toBe(1);
})->with([
    'registration' => ['register', DomainStatus::PendingRegistration],
    'transfer' => ['transfer', DomainStatus::PendingTransfer],
]);

test('a lost claim without info support is flagged for review instead of re-registering', function () {
    $this->registrar->capabilities = ['register', 'sync'];
    $domain = Domain::factory()->pending()->create([
        'tld_id' => $this->com->id,
        'registrar_key' => 'fake',
        'registrar_meta' => ['submission' => now()->subHour()->toIso8601String()],
    ]);

    app(SubmitToRegistrar::class)->handle($domain);

    expect($domain->fresh())->status->toBe(DomainStatus::PendingRegistration)->needs_review->toBeTrue()
        ->and($this->registrar->commands())->toBe([]);
});

test('a retryable error releases the claim so the queue retry may submit again', function () {
    $this->registrar->will('register', new RegistrarException('Rate limited', retryable: true));
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    expect(fn () => app(SubmitToRegistrar::class)->handle($domain))->toThrow(RegistrarException::class);
    expect($domain->fresh()->registrar_meta)->not->toHaveKey('submission');

    $this->registrar->results = [];
    expect(app(SubmitToRegistrar::class)->handle($domain->fresh())->success)->toBeTrue();
});

test('registrar jobs are unique per domain', function () {
    Queue::fake();

    RegisterDomain::dispatch(42);
    RegisterDomain::dispatch(42);
    RenewDomain::dispatch(42, 1, '2027-01-31');
    RenewDomain::dispatch(42, 1, '2027-01-31');

    Queue::assertPushed(RegisterDomain::class, 1);
    Queue::assertPushed(RenewDomain::class, 1);
});

test('an unknown renewal outcome is never re-sent; a later attempt only verifies', function () {
    $this->registrar->capabilities = ['renew', 'sync'];
    $this->registrar->will('renew', new RuntimeException('connection reset'));
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $first = app(PerformRenewal::class)->handle($domain, 1, '2027-01-31');
    $second = app(PerformRenewal::class)->handle($domain->fresh(), 1, '2027-01-31');

    expect($first->success)->toBeFalse()
        ->and($second->success)->toBeFalse()
        ->and($this->registrar->commands())->toBe(['renew'])
        ->and($domain->fresh())->needs_review->toBeTrue()->expiry_date->toDateString()->toBe('2027-01-31');
});

test('a renewal confirmed by the registrar after a lost response is applied once', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $this->registrar->will('renew', new RuntimeException('connection reset'));
    app(PerformRenewal::class)->handle($domain, 1, '2027-01-31');

    $this->registrar->will('info', new DomainInfo('active', expiryDate: CarbonImmutable::parse('2028-01-31')));
    $result = app(PerformRenewal::class)->handle($domain->fresh(), 1, '2027-01-31');

    expect($result->success)->toBeTrue()
        ->and($this->registrar->commands())->toBe(['renew', 'info'])
        ->and($domain->fresh())->expiry_date->toDateString()->toBe('2028-01-31')->needs_review->toBeFalse();
});

test('a sync shifting expiry by a day does not swallow a paid renewal', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $domain->update(['expiry_date' => '2027-02-01']);

    $result = app(PerformRenewal::class)->handle($domain, 1, '2027-01-31');

    expect($result->success)->toBeTrue()
        ->and($this->registrar->commands())->toBe(['renew'])
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2028-02-01');
});

test('a partial payment never sends a renew command', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    event(new PaymentReceived(1, 900, $domain->client_id, 700, 'USD', 'manual'));

    expect($this->registrar->commands())->toBe([])
        ->and($domain->fresh()->expiry_date->toDateString())->toBe('2027-01-31');
});

test('a disabled registrar refuses commands', function () {
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false]);
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    $result = app(DomainRegistry::class)->process($domain->id);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('disabled')
        ->and($this->registrar->calls)->toBe([])
        ->and($domain->fresh()->status)->toBe(DomainStatus::Pending);
});

test('the payment job, a staff command and Orders acceptance submit a domain once', function () {
    actingAsStaff(['domains.*']);
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    event(new InvoicePaid(77, $domain->client_id, [new InvoiceLineData('Domain', 1200, billableType: BillableType::Domain, billableId: $domain->id)]));
    app(DomainRegistry::class)->process($domain->id);
    $this->post(route('admin.domains.command', [$domain, 'register']))->assertSessionHasErrors('domain');

    expect(array_count_values($this->registrar->commands())['register'])->toBe(1);
});
