<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Jobs\FetchServerAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review-board probes for #87 (import accounts, step 1). Each test reproduces a review finding; adopt them into
 * ServerImportTest.php once fixed.
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
            'ip' => '203.0.113.10', 'suspended' => false, 'suspend_reason' => null, 'created_at' => null, 'disk_used_mb' => 100, 'disk_limit_mb' => 1000];
    }

    ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'accounts' => $accounts, 'account_count' => $count, 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
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

test('[N1] the fetch runs on the provisioning queue, not default (ADR 0014)', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();
    Queue::fake();

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    Queue::assertPushedOn('provisioning', FetchServerAccounts::class);
});

test('[N2] a fetch stuck past the stale window can be replaced, and the old job then does nothing', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();
    $this->travelTo(now()->subMinutes(ServerAccountImport::STALE_MINUTES + 1));
    $stale = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'fetching', 'expires_at' => now()->addDay()]);
    $this->travelBack();

    Queue::fake();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    app()->call([new FetchServerAccounts($stale->id), 'handle']);

    expect(ServerAccountImport::query()->pluck('status')->all())->toBe(['fetching'])
        ->and(ServerAccountImport::query()->whereKey($stale->id)->exists())->toBeFalse();
});

test('[N3] a worker that dies mid-fetch ends the fetch as failed, so staff can fetch again', function () {
    $import = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'fetching', 'expires_at' => now()->addDay()]);

    (new FetchServerAccounts($import->id))->failed(new RuntimeException('killed'));

    expect($import->refresh()->status)->toBe('failed')
        ->and($import->error)->toContain('took too long or was interrupted');
});

test('[N4] a driver error that echoes the token is stored masked', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();
    Whm::fake(['listaccts' => fn () => throw new RuntimeException('upstream said: Authorization: whm root:'.Whm::TOKEN)]);

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    $import = ServerAccountImport::query()->sole();
    expect($import->status)->toBe('failed')
        ->and($import->error)->not->toContain(Whm::TOKEN);
});

test('[N5] the prune task deletes expired lists only, and running it twice changes nothing more', function () {
    $old = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'ready', 'accounts' => [], 'expires_at' => now()->subMinute()]);
    $other = Server::factory()->create(['driver' => 'cpanel']);
    $live = ServerAccountImport::query()->create(['server_id' => $other->id, 'status' => 'ready', 'accounts' => [], 'expires_at' => now()->addHour()]);

    $this->artisan('automation:run', ['--date' => now()->toDateString(), '--only' => 'services.import-prune', '--force' => true])->assertSuccessful();
    $this->artisan('automation:run', ['--date' => now()->toDateString(), '--only' => 'services.import-prune', '--force' => true])->assertSuccessful();

    expect(ServerAccountImport::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(ServerAccountImport::query()->whereKey($live->id)->exists())->toBeTrue();
});

test('[N6] a closed client with the email is not proposed', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();
    Client::factory()->create(['email' => 'anna@alpha-books.com', 'status' => 'closed']);

    $this->post(route('admin.services.servers.import.fetch', $this->server));
    $rows = collect($this->get(route('admin.services.servers.import.show', $this->server))->inertiaProps('rows.data'))->keyBy('username');

    expect($rows['alpha']['category'])->toBe('unmatched')
        ->and($rows['alpha']['note'])->toContain('No client has the email anna@alpha-books.com.');
});
