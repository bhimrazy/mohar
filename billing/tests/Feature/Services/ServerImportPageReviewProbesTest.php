<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review-board probes for #101 (import accounts page, step 3a-2). Adopt them into ServerImportPageTest.php once fixed.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $this->group = ServerGroup::factory()->create();
    $this->group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
});

/**
 * A ready import of $count stored rows, with a live service for each of the first $linked usernames.
 */
function reviewLargeImport(Server $server, int $count, int $linked): ServerAccountImport
{
    $template = Service::factory()->create(['server_id' => $server->id, 'username' => 'seed0', 'status' => ServiceStatus::Active, 'product_id' => 21, 'created_at' => now()->subDay()])->getAttributes();
    unset($template['id']);

    foreach (array_chunk(range(1, $linked), 200) as $chunk) {
        DB::table('services')->insert(array_map(fn (int $i): array => [...$template, 'username' => "u{$i}", 'domain' => "d{$i}.test"], $chunk));
    }

    $accounts = [];

    for ($i = 1; $i <= $count; $i++) {
        $accounts[] = ['username' => "u{$i}", 'domain' => "d{$i}.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "c{$i}@example.test",
            'ip' => '203.0.113.10', 'suspended' => false, 'suspend_reason' => null, 'created_at' => null, 'disk_used_mb' => 100, 'disk_limit_mb' => 1000,
            'category' => $i <= $linked ? 'linked' : 'unmatched', 'client_id' => null, 'product_id' => null, 'service_id' => null, 'note' => null, 'differences' => []];
    }

    return ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'accounts' => $accounts, 'account_count' => $count,
        'linked_count' => $linked, 'unmatched_count' => $count - $linked, 'missing' => [], 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
}

test('[P1] one page of a 10,000-account import, searched, reads the live services once and stays within 32 MB', function () {
    actingAsStaff(['services.servers.import']);
    reviewLargeImport($this->server, 10000, 10000);

    $scans = 0;
    DB::listen(function ($query) use (&$scans): void {
        $scans += (int) (str_contains($query->sql, 'from "services"') && str_contains($query->sql, '"server_id" = ?'));
    });

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $this->get(route('admin.services.servers.import.show', [$this->server, 'page' => 7, 'search' => 'u9']))->assertOk();

    expect($scans)->toBeLessThanOrEqual(1)
        ->and((memory_get_peak_usage() - $before) / 1048576)->toBeLessThan(32.0);
});

test('[P2] Match again on a 10,000-account import stays within 64 MB in the request', function () {
    actingAsStaff(['services.servers.import']);
    reviewLargeImport($this->server, 10000, 10000);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    expect((memory_get_peak_usage() - $before) / 1048576)->toBeLessThan(64.0);
});

test('[P3] Match again is recorded in the activity log against the staff member', function () {
    $staff = actingAsStaff(['services.servers.import']);
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server));
    $logged = ActivityLog::query()->count();

    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    expect(ActivityLog::query()->count())->toBe($logged + 1)
        ->and(ActivityLog::query()->latest('id')->value('description'))->toContain('matched again');
});

test('[P4] Match again sends nothing to the server', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server));
    Http::fake();

    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasNoErrors();

    Http::assertNothingSent();
});

test('[P5] Match again while a fetch runs is refused, and the running fetch is left alone', function () {
    actingAsStaff(['services.servers.import']);
    $running = ServerAccountImport::query()->create(['server_id' => $this->server->id, 'status' => 'fetching', 'expires_at' => now()->addDay()]);

    $this->post(route('admin.services.servers.import.rematch', $this->server))->assertSessionHasErrors('import');

    expect($running->refresh()->status)->toBe('fetching');
});

test('[P6] a proposed row whose product no longer provisions here says so, like a closed client does', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server));
    $this->group->servers()->detach($this->server);

    $alpha = collect($this->get(route('admin.services.servers.import.show', $this->server))->inertiaProps('rows.data'))->keyBy('username')['alpha'];

    expect($alpha['product'])->toBeNull()
        ->and((string) $alpha['note'])->toContain('Match again');
});
