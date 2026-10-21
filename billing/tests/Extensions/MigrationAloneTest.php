<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Clients\ClientCurrencyUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;
use Tests\TestCase;

/*
 * The Migration module with only Core (no Billing, Services, Orders or Domains): the currency lock (spec §6.4) asks
 * the kernel registry, which then has no source, so a client import checks and applies without the siblings.
 */

uses(RefreshDatabase::class);

// This file migrates its own in-memory database with only Core and Migration tables; the next file must not reuse it.
$freshDatabase = function (): void {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
};

beforeAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = 'Migration';
});

afterAll(function () use ($freshDatabase): void {
    $freshDatabase();
    TestCase::$onlyModules = null;
});

test('a client import updates the currency when no module holds amounts for the client', function () {
    expect(app()->providerIsLoaded('App\Modules\Services\ServicesServiceProvider'))->toBeFalse()
        ->and(app()->providerIsLoaded('App\Modules\Orders\OrdersServiceProvider'))->toBeFalse()
        ->and(app(ClientCurrencyUsage::class)->keys())->toBe([]);

    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    actingAsStaff();
    confirmPassword();
    $client = Client::factory()->create(['email' => 'asha@example.com', 'currency' => 'NPR']);

    $run = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com', last: 'Changed', currency: 'USD')])), ['on_match' => 'update']));

    expect($run->updated_count)->toBe(1)
        ->and($client->fresh())->currency->toBe('USD')->last_name->toBe('Changed');
});
