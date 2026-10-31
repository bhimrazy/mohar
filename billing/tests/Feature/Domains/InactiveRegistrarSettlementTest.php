<?php

use App\Data\Domains\Registrar\SubmissionLookup;
use App\Enums\ReconcileStatus;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use Carbon\CarbonImmutable;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

/**
 * ADR 0030 A1 (#571): disabling a registrar account stops new commands; reconcile and sync of commands it already
 * sent keep running.
 */
beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->registrar->capabilities[] = 'reconcile';
    $this->com = DomainFixtures::com();
});

test('a registration in flight when its registrar is disabled is reconciled, and a new one is still refused', function () {
    $lost = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake', 'registrar_meta' => ['submission' => now()->subMinutes(20)->toIso8601String()]]);
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false]);
    $this->registrar->will('reconcile', new SubmissionLookup(ReconcileStatus::Executed, CarbonImmutable::parse('2027-05-01')));

    $result = app(SubmitToRegistrar::class)->handle($lost);
    $fresh = Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'registrar_key' => 'fake']);
    $refused = app(SubmitToRegistrar::class)->handle($fresh);

    expect($result->success)->toBeTrue()
        ->and($lost->fresh())->status->toBe(DomainStatus::Active)->needs_review->toBeFalse()->expiry_date->toDateString()->toBe('2027-05-01')
        ->and($refused->success)->toBeFalse()->and($refused->message)->toContain('disabled')
        ->and($this->registrar->commands())->toBe(['reconcile']);
});

test('a renewal in flight when its registrar is disabled is reconciled', function () {
    $domain = Domain::factory()->expiring('2027-01-31')->create([
        'tld_id' => $this->com->id, 'registrar_key' => 'fake',
        'registrar_meta' => ['renew_sent_for' => '2027-01-31', 'renew_sent_at' => now()->subHour()->toIso8601String()],
    ]);
    RegistrarAccount::query()->create(['key' => 'fake', 'is_active' => false]);
    $this->registrar->will('reconcile', new SubmissionLookup(ReconcileStatus::Executed, CarbonImmutable::parse('2028-01-31')));

    app(PerformRenewal::class)->handle($domain, 1, '2027-01-31');

    expect($this->registrar->commands())->toBe(['reconcile'])
        ->and($domain->fresh())->expiry_date->toDateString()->toBe('2028-01-31')->needs_review->toBeFalse();
});
