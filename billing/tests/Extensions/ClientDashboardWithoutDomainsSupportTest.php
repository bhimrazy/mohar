<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * The client dashboard with Billing and Services but no Domains or Support: their panels and counters are absent and
 * the page still renders.
 */

uses(RefreshDatabase::class);

// This file migrates its own in-memory database with a module subset; the next file must not reuse it.
$freshDatabase = function (): void {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
};

beforeAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = 'Billing,Services';
});

afterAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = null;
});

test('panels of modules that are not installed are hidden, the rest still show', function () {
    expect(app()->providerIsLoaded('App\Modules\Domains\DomainsServiceProvider'))->toBeFalse()
        ->and(app()->providerIsLoaded('App\Modules\Support\SupportServiceProvider'))->toBeFalse();

    Currency::factory()->npr()->create();
    actingAsClient(Client::factory()->create(['currency' => 'NPR']));

    $this->get(route('client.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('client/dashboard')
        ->where('panels', fn ($panels) => collect($panels)->pluck('key')->all() === ['services.list', 'billing.invoices'])
        ->where('cards', fn ($cards) => collect($cards)->pluck('key')->intersect(['domains.expiring', 'support.open'])->isEmpty()));
});
