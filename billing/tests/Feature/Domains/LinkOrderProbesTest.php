<?php

/*
 * Review probes for #365 (rb365), adopted: name matching, reseller, customer and status checks, .np, and the order lock.
 */

use App\Data\Domains\Registrar\LinkedOrder;
use App\Modules\Domains\Actions\LinkRegistrarOrder;
use App\Modules\Domains\Drivers\Registrars\ResellerClub\ResellerClubDriver;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Modules\Domains\Models\Tld;
use App\Modules\Domains\Services\RegistrarManager;
use App\Support\Domains\RegistrarException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Domains\DomainFixtures;
use Tests\Fakes\Domains\FakeRegistrar;

/** The answer the faked ResellerClub gives; one fake serves every call in a test, so it reads this. */
function rb365Details(?array $set = null): array
{
    static $details = [];

    return $details = $set ?? $details;
}

function rb365Driver(array $details, string $sld = 'imported-sim', string $ext = '.com'): string
{
    RegistrarAccount::query()->updateOrCreate(['key' => ResellerClubDriver::KEY], ['is_active' => true, 'test_mode' => true, 'settings' => [
        'demo_reseller_id' => '555555', 'demo_api_key' => 'k', 'customer_mode' => 'default_customer', 'default_customer_id' => '14000001',
    ]]);
    rb365Details($details);
    Http::fake(fn () => Http::response(rb365Details()));
    $tld = Tld::query()->where('extension', $ext)->first() ?? Tld::factory()->extension($ext)->priced()->create(['registrar_key' => 'resellerclub']);
    $domain = Domain::factory()->named($sld, $tld)->create(['registrar_key' => 'resellerclub', 'status' => DomainStatus::Active])->fresh();

    try {
        app(ResellerClubDriver::class)->lookupOrder(app(RegistrarManager::class)->config('resellerclub'), $domain->toRef(), '80000001');

        return 'accepted';
    } catch (RegistrarException $e) {
        return 'refused: '.$e->getMessage();
    }
}

$base = ['orderid' => '80000001', 'domainname' => 'imported-sim.com', 'currentstatus' => 'Active', 'resellerid' => '555555', 'customerid' => '14000001', 'endtime' => (string) (time() + 86400 * 200)];

test('rb365 names, reseller and customer forms', function () use ($base) {
    $cases = [
        'exact' => $base,
        'upper' => [...$base, 'domainname' => 'IMPORTED-SIM.COM'],
        'trailing dot' => [...$base, 'domainname' => 'imported-sim.com.'],
        'other name' => [...$base, 'domainname' => 'imported-sim.net'],
        'no domainname, description only' => [...array_diff_key($base, ['domainname' => 1]), 'description' => 'imported-sim.com'],
        'int ids' => [...$base, 'orderid' => 80000001, 'customerid' => 14000001, 'resellerid' => 555555],
        'reseller missing' => array_diff_key($base, ['resellerid' => 1]),
        'reseller other' => [...$base, 'resellerid' => '777777'],
        'customer other' => [...$base, 'customerid' => '14000002'],
        'customer missing' => array_diff_key($base, ['customerid' => 1]),
        'orderid other' => [...$base, 'orderid' => '80000002'],
        'deleted lowercase' => [...$base, 'currentstatus' => 'deleted'],
    ];
    $out = [];
    foreach ($cases as $label => $details) {
        Domain::query()->forceDelete();
        $out[$label] = rb365Driver($details);
    }
    expect($out['trailing dot'])->toStartWith('refused')->and($out['other name'])->toStartWith('refused')
        ->and($out['reseller other'])->toStartWith('refused')->and($out['customer other'])->toStartWith('refused')
        ->and($out['customer missing'])->toStartWith('refused')->and($out['orderid other'])->toStartWith('refused')
        ->and($out['deleted lowercase'])->toStartWith('refused')->and($out['exact'])->toBe('accepted')->and($out['upper'])->toBe('accepted')
        ->and($out['int ids'])->toBe('accepted');
});

test('rb365 IDN: a Unicode answer for a punycode row is refused, an upper-case punycode one is accepted', function () use ($base) {
    $a = rb365Driver([...$base, 'domainname' => 'bücher.com'], 'xn--bcher-kva');
    Domain::query()->forceDelete();
    $b = rb365Driver([...$base, 'domainname' => 'XN--BCHER-KVA.COM'], 'xn--bcher-kva');
    expect($a)->toStartWith('refused')->and($b)->toBe('accepted');
});

test('rb365 .np never reaches ResellerClub', function () use ($base) {
    $r = rb365Driver([...$base, 'domainname' => 'imported-sim.com.np'], 'imported-sim', '.com.np');
    expect($r)->toStartWith('refused')->and(Http::recorded())->toHaveCount(0);
});

test('rb365 lock: the order lock expires while the lookup still runs; a second link of the same order to a same-name row is then admitted', function () {
    $registrar = FakeRegistrar::install();
    $registrar->capabilities[] = 'link_order';
    $com = DomainFixtures::com();
    $a = Domain::factory()->create(['tld_id' => $com->id, 'registrar_key' => 'fake', 'status' => DomainStatus::Active]);
    $b = Domain::factory()->create(['tld_id' => $com->id, 'registrar_key' => 'fake', 'status' => DomainStatus::Expired, 'sld' => $a->sld, 'name' => $a->name, 'name_unicode' => $a->name]);

    // The order lock is 15 s; a ResellerClub read may take 15 s and the domain lock and gateway add to that. Model it by
    // travelling past the TTL while A's lookup is in flight, and let B get the order lock in that moment.
    $seen = null;
    $registrar->will('link_order', function () use (&$seen) {
        $this->travel(16)->seconds();
        $seen = Cache::lock('domains:link-order:fake:80000001', 15)->get() ? 'second linker gets the order lock' : 'still held';

        return new LinkedOrder(['order_id' => 80000001], 'active');
    });

    try {
        app(LinkRegistrarOrder::class)->handle($a, '80000001');
    } catch (Throwable $e) {
    }
    expect($seen)->toBe('still held');
});
