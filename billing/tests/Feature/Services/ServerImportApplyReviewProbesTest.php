<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review-board probes for #95 (import accounts, step 3b). Adopt them into ServerImportApplyTest.php once fixed.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->client = Client::factory()->create(['email' => 'owner@example.com', 'currency' => 'USD']);
    actingAsStaff();
    confirmPassword();
});

/**
 * A ready import of $count accounts, all ticked for one client and product.
 */
function tickedImport(Server $server, int $clientId, int $count, string $createdAt = '2025-03-15T00:00:00+00:00'): ServerAccountImport
{
    $accounts = [];
    $selections = [];

    for ($i = 1; $i <= $count; $i++) {
        $accounts[] = ['username' => "acct{$i}", 'domain' => "site{$i}.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "a{$i}@example.test", 'ip' => '203.0.113.10',
            'suspended' => false, 'suspend_reason' => null, 'created_at' => $createdAt, 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
            'category' => 'unmatched', 'client_id' => null, 'product_id' => null, 'service_id' => null, 'note' => null, 'differences' => []];
        $selections["acct{$i}"] = ['include' => true, 'client_id' => $clientId, 'product_id' => 21];
    }

    return ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'accounts' => $accounts, 'account_count' => $count, 'missing' => [],
        'connection_user' => 'root', 'selections' => $selections, 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
}

test('[A1] applying hundreds of accounts is handed to the queue, not run inside the web request', function () {
    tickedImport($this->server, $this->client->id, 300);
    Queue::fake();

    $this->post(route('admin.services.servers.import.apply', $this->server))->assertRedirect();

    // At ce072f42 all 300 services are created in the request (PostgreSQL: about 10 ms and 6 queries per account,
    // 10.3 s for 1,000), and "Select all proposed" can tick every proposed account on the server.
    expect(Queue::pushedJobs())->not->toBeEmpty()
        ->and(Service::query()->count())->toBeLessThan(300);
});

test('[A2] the review prices identical accounts once, not once per row', function () {
    tickedImport($this->server, $this->client->id, 300);

    $this->get(route('admin.services.servers.import.review', $this->server))->assertOk();

    // One client, one product, one cycle: one quote. At ce072f42: 300 quotes, each 4 queries with the real Catalog
    // (product, prices, config option groups, client group discount).
    expect(count($this->kit->priceBook->quotedSelections))->toBeLessThanOrEqual(1);
});

test('[A3] an account created on the 31st keeps the 31st as its billing anchor when the next due date falls in a shorter month', function () {
    tickedImport($this->server, $this->client->id, 1, '2025-03-31T00:00:00+00:00');

    $this->post(route('admin.services.servers.import.apply', $this->server))->assertSessionHasNoErrors();

    $service = Service::query()->sole();
    expect($service->next_due_date->toDateString())->toBe('2026-11-30')
        ->and($service->billing_anchor_day)->toBe(31);
});
