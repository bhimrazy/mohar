<?php

use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\SyncResult;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Actions\ConvertTldPrices;
use App\Modules\Domains\Actions\IssueRenewalNow;
use App\Modules\Domains\Actions\PerformRenewal;
use App\Modules\Domains\Actions\SyncDomain;
use App\Modules\Domains\Exceptions\DomainActionNotAllowed;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Models\TldPrice;
use App\Modules\Domains\Services\DomainPricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeInvoicer;
use Tests\Fakes\Domains\FakeRegistrar;

beforeEach(function () {
    $this->registrar = FakeRegistrar::install();
    $this->invoicer = FakeInvoicer::bind();
    $this->com = DomainFixtures::com();
    $this->lostRenewal = fn (array $meta = []): Domain => Domain::factory()->expiring('2027-01-31')->create([
        'tld_id' => $this->com->id, 'registrar_key' => 'fake', 'needs_review' => true, 'last_error' => 'The registrar did not answer.',
        'registrar_meta' => ['renew_sent_for' => '2027-01-31', 'renew_sent_at' => now()->subDay()->toIso8601String(), 'renew_years' => 1, ...$meta],
    ]);
});

test('a sync that finds the lost renewal at the registrar clears the review flag and the claim (#13)', function () {
    $domain = ($this->lostRenewal)();
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse('2028-01-31')));

    $result = app(SyncDomain::class)->handle($domain);

    expect($result['error'])->toBeNull()
        ->and($domain->fresh())->needs_review->toBeFalse()->last_error->toBeNull()->registrar_meta->toBeNull()
        ->expiry_date->toDateString()->toBe('2028-01-31');

    // Next year's renewal is no longer held for staff.
    $this->registrar->will('renew', new RegistrarResult('completed', CarbonImmutable::parse('2029-01-31')));
    app(PerformRenewal::class)->handle($domain->fresh(), 1, '2028-01-31');

    expect($this->registrar->commands())->toContain('renew')->and($domain->fresh()->expiry_date->toDateString())->toBe('2029-01-31');
});

test('the claim also closes when the expiry was already corrected locally', function () {
    $domain = ($this->lostRenewal)();
    $domain->forceFill(['expiry_date' => '2028-01-31', 'next_due_date' => '2028-01-31'])->save();
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse('2028-01-31')));

    app(SyncDomain::class)->handle($domain);

    expect($domain->fresh())->needs_review->toBeFalse()->registrar_meta->toBeNull();
});

test('a sync that does not reach the claimed cycle, or only reports, keeps the review', function (string $remote, bool $notifyOnly) {
    $domain = ($this->lostRenewal)(['renew_years' => 2]);
    $this->registrar->will('sync', new SyncResult(true, expiryDate: CarbonImmutable::parse($remote)));

    app(SyncDomain::class)->handle($domain, notifyOnly: $notifyOnly);

    expect($domain->fresh())->needs_review->toBeTrue()->registrar_meta->toHaveKey('renew_sent_for');
})->with([
    'one of two paid years' => ['2028-01-31', false],
    'confirmed but notify-only' => ['2029-01-31', true],
]);

test('Renew now refuses while another request is already creating the invoice (#15)', function () {
    $domain = Domain::factory()->create(['tld_id' => $this->com->id]);
    $held = Cache::lock('domains:renew-now:'.$domain->id, 30);
    $held->get();

    expect(fn () => app(IssueRenewalNow::class)->handle($domain, 'staff'))->toThrow(DomainActionNotAllowed::class);
    expect($this->invoicer->issued())->toBe([]);

    $held->release();
    app(IssueRenewalNow::class)->handle($domain, 'staff');

    expect($this->invoicer->issued())->toHaveCount(1)
        ->and(fn () => app(IssueRenewalNow::class)->handle($domain, 'staff'))->toThrow(DomainActionNotAllowed::class);
});

test('terms follow the client group tier like prices do (#15)', function () {
    $group = ClientGroup::query()->create(['name' => 'Resellers']);
    TldPrice::query()->create(['tld_id' => $this->com->id, 'currency' => 'USD', 'client_group_id' => $group->id, 'years' => 1, 'register' => 900, 'renew' => 1000]);
    $slab = Tld::factory()->extension('.shop')->create();
    TldPrice::query()->create(['tld_id' => $slab->id, 'currency' => 'USD', 'client_group_id' => $group->id, 'years' => 3, 'register' => 2000, 'renew' => 2100]);
    $member = Client::factory()->create(['client_group_id' => $group->id]);
    $pricing = app(DomainPricingService::class);

    expect($pricing->terms('.shop', 'register', 'USD'))->toBe([])
        ->and($pricing->terms('.shop', 'register', 'USD', $member->id))->toBe([3])
        ->and($pricing->price('.shop', 'register', 3, 'USD', $member->id))->toBe(2000);
});

test('converting prices never turns a paid price into a free one, and keeps an explicit free transfer (#15)', function () {
    Currency::factory()->create();
    Currency::factory()->create(['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'prefix' => 'KD ', 'exchange_rate' => 0.1, 'is_default' => false]);
    $paid = Tld::factory()->extension('.paid')->priced(register: 1200, transfer: 1, renew: 1400, years: 1)->create();
    $free = Tld::factory()->extension('.free')->priced(register: 1200, transfer: 0, renew: 1400, years: 1)->create();

    app(ConvertTldPrices::class)->apply();

    expect(TldPrice::query()->where('tld_id', $paid->id)->where('currency', 'KWD')->sole()->transfer)->toBe(1)
        ->and(TldPrice::query()->where('tld_id', $free->id)->where('currency', 'KWD')->sole()->transfer)->toBe(0);
});

test('a lost race between two first saves of a TLD price grid asks for a reload instead of failing (#55)', function () {
    actingAsStaff(['domains.tlds.*']);
    Currency::factory()->create();
    $tld = Tld::factory()->extension('.race')->create();
    $rival = true;
    TldPrice::creating(function (TldPrice $price) use (&$rival, $tld): void {
        if ($rival) {
            $rival = false;
            DB::table('tld_prices')->insert(['tld_id' => $tld->id, 'currency' => 'USD', 'client_group_id' => null, 'years' => 1, 'register' => 500, 'renew' => 600, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->put(route('admin.domains.tlds.prices', $tld), ['currency' => 'USD', 'prices' => [['years' => 1, 'register' => 800, 'transfer' => null, 'renew' => 900]]])
        ->assertSessionHasErrors('prices');

    $this->put(route('admin.domains.tlds.prices', $tld), ['currency' => 'USD', 'prices' => [['years' => 1, 'register' => 800, 'transfer' => null, 'renew' => 900]]])
        ->assertSessionDoesntHaveErrors();
    expect(TldPrice::query()->where('tld_id', $tld->id)->sole()->register)->toBe(800);
});
