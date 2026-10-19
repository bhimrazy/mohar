<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review-board probe B1 for #87 (import accounts), adopted with the page: the stored list now carries each row's
 * match, as the fetch job writes it.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
});

/**
 * A ready import of $count accounts, with a live service on the server for each of the first $linked usernames.
 */
function largeImport(Server $server, int $count, int $linked): void
{
    $template = Service::factory()->create(['server_id' => $server->id, 'username' => 'seed0', 'status' => ServiceStatus::Active, 'product_id' => 21])->getAttributes();
    unset($template['id']);
    $rows = [];

    for ($i = 1; $i <= $linked; $i++) {
        $rows[] = [...$template, 'username' => "u{$i}", 'domain' => "d{$i}.test"];

        if (count($rows) === 200) {
            DB::table('services')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('services')->insert($rows);
    }

    $accounts = [];

    for ($i = 1; $i <= $count; $i++) {
        $accounts[] = ['username' => "u{$i}", 'domain' => "d{$i}.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "c{$i}@example.test",
            'ip' => '203.0.113.10', 'suspended' => false, 'suspend_reason' => null, 'created_at' => null, 'disk_used_mb' => 100, 'disk_limit_mb' => 1000,
            'category' => $i <= $linked ? 'linked' : 'unmatched', 'client_id' => null, 'product_id' => null, 'service_id' => null, 'note' => null, 'differences' => []];
    }

    ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'accounts' => $accounts, 'account_count' => $count, 'linked_count' => $linked, 'unmatched_count' => $count - $linked, 'missing' => [], 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
}

test('[B1] one page of a 5,000-account import reads the live services once and stays within a memory budget', function () {
    actingAsStaff(['services.servers.import']);
    largeImport($this->server, 5000, 5000);

    $serviceScans = 0;
    DB::listen(function ($query) use (&$serviceScans): void {
        if (str_contains($query->sql, 'from "services"') && str_contains($query->sql, '"server_id" = ?')) {
            $serviceScans++;
        }
    });

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $this->get(route('admin.services.servers.import.show', [$this->server, 'page' => 3]))->assertOk();
    $used = (memory_get_peak_usage() - $before) / 1048576;

    // At 7940e84: two scans (classify() and missing() each load every live service with its client), and the peak
    // grows with the server's size; at 10k accounts / 10k live services on PostgreSQL it passes PHP's default 128 MB.
    expect($serviceScans)->toBeLessThanOrEqual(1)
        ->and($used)->toBeLessThan(32.0);
});
