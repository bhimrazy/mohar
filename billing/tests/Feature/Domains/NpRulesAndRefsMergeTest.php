<?php

use App\Contracts\Domains\DomainNameRules;
use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\NullDomainNameRules;
use App\Modules\Domains\Models\Tld;
use App\Support\Clients\ClientMergeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Currency::factory()->create();
    config(['domains.lookup_registrar' => 'log']);
});

test('the .np second-level rule has one source: the contract answers for it, and the null default restricts nothing', function () {
    $rules = app(DomainNameRules::class);

    expect($rules->registryLevelError('asha.np'))->toContain('yourname.com.np')
        ->and($rules->registryLevelError('asha.com.np'))->toBeNull()
        ->and($rules->registryLevelError('asha.com'))->toBeNull()
        ->and((new NullDomainNameRules)->registryLevelError('asha.np'))->toBeNull()
        ->and(Tld::registryLevelHint('asha.np'))->toContain('.com.np')->toContain('.org.np')
        ->and(Tld::registryLevelHint('asha.com.np'))->toBeNull();
});

test('domain search shows the TLD\'s own how-to link and falls back to the host-wide one', function () {
    Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'log', 'how_to_url' => 'https://example.com/np-guide']);
    Tld::factory()->extension('.org.np')->priced()->create(['registrar_key' => 'log']);

    $this->getJson(route('domains.lookup', ['q' => 'asha.com.np']))->assertJsonPath('results.0.registration_url', 'https://example.com/np-guide');
    $this->getJson(route('domains.lookup', ['q' => 'asha.org.np']))->assertJsonPath('results.0.registration_url', 'https://register.com.np/how-to-register-np-domain');
});

test('registrar refs follow a merged client; the target keeps its own where both have the same ref', function () {
    $refs = app(RegistrarRefs::class);
    $source = Client::factory()->create();
    $target = Client::factory()->create();
    $other = Client::factory()->create();
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', $source->id, 'src-cust'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', $source->id, 'src-a', 'hash-a'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', $source->id, 'src-b', 'hash-b'));
    $refs->remember(new RegistrarRef('namecheap', 'customer', 'client', $source->id, 'src-nc'));
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', $target->id, 'tgt-cust'));
    $refs->remember(new RegistrarRef('resellerclub', 'contact', 'client', $target->id, 'tgt-b', 'hash-b'));
    $refs->remember(new RegistrarRef('resellerclub', 'customer', 'client', $other->id, 'other-cust'));

    $preview = collect(app(ClientMergeRegistry::class)->preview($source->id, $target->id))->keyBy('key');
    expect($preview['domains.registrar_refs']['count'])->toBe(4);

    actingAsStaff(['core.clients.view', 'core.clients.merge']);
    confirmPassword();
    $this->post(route('admin.platform.clients.merge', $source), ['target_id' => $target->id, 'confirmation' => 'MERGE'])->assertSessionHasNoErrors()->assertRedirect();

    expect($refs->find('resellerclub', 'customer', 'client', $target->id)?->remoteId)->toBe('tgt-cust')
        ->and($refs->find('resellerclub', 'contact', 'client', $target->id, 'hash-a')?->remoteId)->toBe('src-a')
        ->and($refs->find('resellerclub', 'contact', 'client', $target->id, 'hash-b')?->remoteId)->toBe('tgt-b')
        ->and($refs->find('namecheap', 'customer', 'client', $target->id)?->remoteId)->toBe('src-nc')
        ->and($refs->find('resellerclub', 'customer', 'client', $other->id)?->remoteId)->toBe('other-cust')
        ->and(DB::table('registrar_refs')->where('local_id', $source->id)->count())->toBe(0)
        ->and(DB::table('registrar_refs')->count())->toBe(5);
});
