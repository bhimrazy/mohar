<?php

use App\Events\Domains\DomainSyncReport;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Actions\SyncDomains;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

/**
 * Registrar prerequisites (registrars.md §2): remote ids (C1), the authoritative reconcile (C2) and the sweep of
 * submissions a killed worker left behind (C3, issue #12).
 */
beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->registrar->capabilities[] = 'reconcile';
    $this->com = DomainFixtures::com();
    $this->lostClaim = fn (string $type = 'register', int $minutesAgo = 20): Domain => Domain::factory()->pending($type)->create([
        'tld_id' => $this->com->id,
        'registrar_key' => 'fake',
        'registrar_meta' => ['submission' => now()->subMinutes($minutesAgo)->toIso8601String()],
    ]);
});

test('a lost submission is resolved from the registrar account and never sent again', function (SubmissionLookup $lookup, DomainStatus $status, bool $review) {
    $this->registrar->will('reconcile', $lookup);
    $domain = ($this->lostClaim)();

    app(SubmitToRegistrar::class)->handle($domain);

    expect($this->registrar->commands())->toContain('reconcile')->not->toContain('register')
        ->and($domain->fresh())->status->toBe($status)->needs_review->toBe($review);
})->with([
    'executed' => [new SubmissionLookup('executed', CarbonImmutable::parse('2027-05-01')), DomainStatus::Active, false],
    'in progress' => [new SubmissionLookup('in_progress'), DomainStatus::PendingRegistration, false],
    'absent' => [new SubmissionLookup('absent'), DomainStatus::PendingRegistration, true],
    'unknown' => [new SubmissionLookup('unknown'), DomainStatus::PendingRegistration, true],
]);

test('an executed lookup applies the registrar expiry and keeps the ids it found', function () {
    $this->registrar->will('reconcile', new SubmissionLookup('executed', CarbonImmutable::parse('2027-05-01'), ['order_id' => 9182]));
    $domain = ($this->lostClaim)();

    app(SubmitToRegistrar::class)->handle($domain);

    expect($domain->fresh())->expiry_date->toDateString()->toBe('2027-05-01')
        ->registrar_meta->toBe(['remote' => ['order_id' => 9182]]);
});

test('registrar ids from results are kept for later calls; malformed ones are dropped', function () {
    $this->registrar->will('register', new RegistrarResult('completed', CarbonImmutable::parse('2027-05-01'), remote: ['order_id' => 77, 'Bad Key' => 'x', 'nested' => ['a' => 1]]));
    $domain = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    app(SubmitToRegistrar::class)->handle($domain);

    $this->registrar->will('renew', new RegistrarResult('completed', CarbonImmutable::parse('2028-05-01'), remote: ['renewal_id' => 'R-5']));
    app(PerformRenewal::class)->handle($domain->fresh(), 1, '2027-05-01');

    expect($domain->fresh()->registrar_meta['remote'])->toBe(['order_id' => 77, 'renewal_id' => 'R-5']);
});

test('a lost renewal is applied only when the registrar shows it ran', function (SubmissionLookup $lookup, string $expiry, bool $review) {
    $this->registrar->will('reconcile', $lookup);
    $domain = Domain::factory()->expiring('2027-01-31')->create([
        'tld_id' => $this->com->id, 'registrar_key' => 'fake',
        'registrar_meta' => ['renew_sent_for' => '2027-01-31', 'renew_sent_at' => now()->subHour()->toIso8601String()],
    ]);

    app(PerformRenewal::class)->handle($domain, 1, '2027-01-31');

    expect($this->registrar->commands())->toBe(['reconcile'])
        ->and($domain->fresh())->expiry_date->toDateString()->toBe($expiry)->needs_review->toBe($review);
})->with([
    'executed' => [new SubmissionLookup('executed', CarbonImmutable::parse('2028-01-31')), '2028-01-31', false],
    'executed with an expiry short of the cycle' => [new SubmissionLookup('executed', CarbonImmutable::parse('2027-06-01')), '2027-01-31', true],
    'absent' => [new SubmissionLookup('absent'), '2027-01-31', true],
]);

test('the sync sweep flags and verifies a submission a killed worker left pending (#12)', function () {
    Event::fake([DomainSyncReport::class]);
    $this->registrar->capabilities = ['register', 'sync'];
    $this->registrar->will('sync', new SyncResult(false));
    $stale = ($this->lostClaim)();
    $running = ($this->lostClaim)(minutesAgo: 5);

    app(SyncDomains::class)->handle();

    expect($stale->fresh())->status->toBe(DomainStatus::PendingRegistration)->needs_review->toBeTrue()
        ->and($running->fresh())->status->toBe(DomainStatus::Pending)->needs_review->toBeFalse()
        ->and($this->registrar->commands())->not->toContain('register')
        ->and(ActivityLog::query()->where('subject_id', $stale->id)->where('description', 'like', 'Staff task: the register of%')->exists())->toBeTrue();
    Event::assertDispatched(DomainSyncReport::class, fn (DomainSyncReport $report): bool => $report->errors[0]['domain_id'] === $stale->id);
});

test('the sweep still runs when registrar sync is switched off', function () {
    app(Settings::class)->set('domains.sync_enabled', false);
    $this->registrar->will('reconcile', new SubmissionLookup('executed', CarbonImmutable::parse('2027-05-01')));
    $stale = ($this->lostClaim)();

    $result = app(SyncDomains::class)->handle();

    expect($result['synced'])->toBe(0)
        ->and($stale->fresh()->status)->toBe(DomainStatus::Active);
});

test('a sync run stops taking domains once its time budget is spent; they go first next run', function () {
    $domain = Domain::factory()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);

    expect(app(SyncDomains::class)->handle(budgetSeconds: 0)['synced'])->toBe(0)
        ->and(app(SyncDomains::class)->handle()['synced'])->toBe(1)
        ->and($domain->fresh()->last_synced_at)->not->toBeNull();
});
