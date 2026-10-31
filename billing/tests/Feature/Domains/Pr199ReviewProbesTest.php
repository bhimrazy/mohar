<?php

use App\Contracts\Domains\DomainNameRules;
use App\Contracts\Domains\DomainRegistry;
use App\Data\Domains\DomainDraft;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\Tld;
use App\Modules\Orders\Services\DomainGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Review probes for PR #199, adopted as regression tests (.np registered by the client).
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Currency::factory()->create();
    config(['domains.lookup_registrar' => 'log']);
});

test('probe: the DomainRegistry contract refuses a pending registration under a self-registered TLD', function (string $action) {
    Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'log']);
    $client = Client::factory()->create();

    expect(fn () => app(DomainRegistry::class)->createPending(new DomainDraft(
        clientId: $client->id, domain: 'asha.com.np', action: $action, years: 1, currency: 'USD',
        recurringAmount: 1000, firstPaymentAmount: 1000, eppCode: 'X',
    )))->toThrow(ValidationException::class);

    expect(Domain::query()->count())->toBe(0);
})->with(['register', 'transfer']);

test('probe: domain search does not offer "I already have it" for a bare .np name the store then drops', function () {
    Tld::factory()->extension('.np')->create(['registrar_key' => 'log']);

    $result = $this->getJson(route('domains.lookup', ['q' => 'asha.np']))->assertOk()->json('results.0');

    expect($result['status'])->toBe('self_registered')
        ->and($result['existing_url'])->toBeNull();
});

test('probe: the "How to register" URL setting accepts https only', function (string $url, bool $ok) {
    actingAsStaff(['*']);
    session(['auth.step_up_confirmed_at' => now()->getTimestamp()]);

    $response = $this->put(route('admin.platform.settings.update', ['section' => 'domains_search']), ['domains' => ['self_registration_url' => $url]]);

    $ok ? $response->assertSessionDoesntHaveErrors(['domains.self_registration_url'])
        : $response->assertSessionHasErrors(['domains.self_registration_url']);
})->with([
    'javascript' => ['javascript:alert(1)', false],
    'javascript in https costume' => ['javascript://https://x.test/%0aalert(1)', false],
    'data' => ['data:text/html,<script>alert(1)</script>', false],
    'http' => ['http://register.com.np/how-to-register-np-domain', false],
    'https' => ['https://register.com.np/how-to-register-np-domain', true],
]);

test('probe: the migration backfills existing .np TLDs only and rolls back cleanly', function () {
    $migration = require base_path('app/Modules/Domains/Database/Migrations/2026_10_21_500500_add_registered_by_client_to_tlds_table.php');
    $migration->down();
    expect(Schema::hasColumn('tlds', 'registered_by_client'))->toBeFalse();

    foreach (['.np', '.com.np', '.org.np', '.com', '.co.uk', '.npx', '.xnp'] as $index => $extension) {
        DB::table('tlds')->insert([...Tld::factory()->extension($extension)->raw(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $migration->up();

    expect(DB::table('tlds')->where('registered_by_client', true)->orderBy('extension')->pluck('extension')->all())
        ->toBe(['.com.np', '.np', '.org.np'])
        ->and(DB::table('tlds')->where('registered_by_client', false)->count())->toBe(4);

    $migration->down();
    expect(Schema::hasColumn('tlds', 'registered_by_client'))->toBeFalse();
    $migration->up();
});

test('probe: second-level validation edge cases', function (string $name, bool $valid, bool $levelOk) {
    expect(DomainGuard::isValidName($name))->toBe($valid);

    if ($valid) {
        expect(app(DomainNameRules::class)->registryLevelError($name) === null)->toBe($levelOk);
    }
})->with([
    'the second level itself' => ['com.np', true, false],
    'gov.np itself' => ['gov.np', true, false],
    'upper case' => ['ASHA.COM.NP', true, true],
    'punycode label' => ['xn--l2bey1c2b.com.np', true, true],
    'third level host' => ['www.asha.com.np', true, true],
    'unknown second level' => ['asha.xyz.np', true, false],
    'bare .np' => ['asha.np', true, false],
    'trailing dot' => ['asha.com.np.', false, false],
    'unicode label' => ['आशा.com.np', false, false],
]);
