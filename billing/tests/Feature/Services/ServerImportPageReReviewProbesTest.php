<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Re-review probes for #101 (import page, step 3a-2), adopted. R1 and R2 reproduce round-1
 * board items still open; R3 and R4 are issues introduced by the fix commit 269509eb.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->kit->priceBook->addProduct(22, 'Reseller starter', driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'reseller1_starter']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com']);
    Client::factory()->create(['email' => 'chef@bravo-cafe.com']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff(['services.servers.import']);
    confirmPassword();
});

test('[R1] a legacy service with a mixed-case username, linked at match time, is still linked on the page', function () {
    $service = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'Alpha', 'status' => ServiceStatus::Active, 'created_at' => now()->subDay()]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    $alpha = collect($this->get(route('admin.services.servers.import.show', $this->server))->assertOk()->inertiaProps('rows.data'))->keyBy('username')['alpha'];

    expect($alpha['category'])->toBe('linked')
        ->and($alpha['service']['id'] ?? null)->toBe($service->id)
        ->and((string) $alpha['note'])->not->toContain('no longer live');
});

test('[R2] the Missing on server count agrees with the rows the missing tab lists', function () {
    $gone = Service::factory()->create(['server_id' => $this->server->id, 'username' => 'gone1', 'status' => ServiceStatus::Active, 'created_at' => now()->subDay()]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $gone->update(['status' => ServiceStatus::Terminated]);

    $response = $this->get(route('admin.services.servers.import.show', [$this->server, 'tab' => 'missing']))->assertOk();

    expect($response->inertiaProps('counts.missing'))->toBe($response->inertiaProps('rows.total'));
});

test('[R3] a Match again whose job never ran leaves the list usable once it is stale', function () {
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    Queue::fake(); // the match job is lost (worker down, dispatch failed)
    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    $this->travel(ServerAccountImport::STALE_MINUTES + 1)->minutes();
    confirmPassword();

    $response = $this->get(route('admin.services.servers.import.show', $this->server))->assertOk();
    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    expect($response->inertiaProps('rows.data'))->not->toBeEmpty();
});

test('[R4] Match again on a list stored without connection_user never proposes a reseller\'s account', function () {
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->where('server_id', $this->server->id)->sole();
    expect(collect($import->accountRows())->keyBy('username')['bravo']['category'])->toBe('reseller');

    // A list fetched before 2026_10_19_400450 added the column (lists live 24 hours across the deploy).
    DB::table('server_account_imports')->where('id', $import->id)->update(['connection_user' => null]);
    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    expect(collect($import->refresh()->accountRows())->keyBy('username')['bravo']['category'])->not->toBe('proposed');
});
