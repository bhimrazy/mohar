<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\InstallSecrets;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Jobs\ImportReviewedAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Review-board probes for #453 (server import storage, P3a).
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    actingAsStaff();
    confirmPassword();
});

function p453Account(string $username, array $extra = []): array
{
    return [...['username' => $username, 'domain' => "{$username}.example.test", 'plan' => 'root_basic', 'owner' => 'root', 'email' => "{$username}@example.test", 'ip' => null,
        'suspended' => false, 'suspend_reason' => null, 'created_at' => '2025-03-15T00:00:00+00:00', 'disk_used_mb' => 1, 'disk_limit_mb' => 10,
        'category' => 'unmatched', 'client_id' => null, 'product_id' => null, 'service_id' => null, 'note' => null, 'differences' => []], ...$extra];
}

/** A pre-upgrade list: the whole list in the old `accounts` column, no rows. */
function p453Legacy(Server $server, array $accounts, array $attributes = []): ServerAccountImport
{
    $import = ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'account_count' => count($accounts), 'missing' => [],
        'unmatched_count' => count($accounts), 'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay(), ...$attributes]);
    DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => Crypt::encryptString(json_encode($accounts))]);

    return $import;
}

test('[B1] adopt is not atomic: a move that fails part-way leaves a truncated list that is never completed', function () {
    // 401 rows = three insert chunks of 200. The move fails once after its second chunk (any failure mid-move: the
    // memory limit on a 20k-row legacy blob decrypted in the page request, a timeout, a lost connection).
    $import = p453Legacy($this->server, array_map(fn (int $i): array => p453Account("u{$i}"), range(1, 401)));
    $inserts = 0;
    DB::listen(function ($query) use (&$inserts): void {
        if (str_contains($query->sql, 'into "server_account_import_accounts"') && ++$inserts === 2) {
            throw new RuntimeException('interrupted mid-move');
        }
    });

    $this->get(route('admin.services.servers.import.show', $this->server))->assertStatus(500);
    $this->get(route('admin.services.servers.import.show', $this->server))->assertOk();

    // The list says 401 accounts; after a retry every one of them is expected in rows.
    expect($import->refresh()->account_count)->toBe(401)
        ->and(DB::table('server_account_import_accounts')->count())->toBe(401);
});

test('[B2] an import running across the upgrade: its chunks never adopt the old list, so every account is reported missing', function () {
    $accounts = array_map(fn (string $u): array => p453Account($u, ['category' => 'proposed', 'client_id' => $this->anna->id, 'product_id' => 21, 'email' => 'anna@alpha-books.com']), ['alpha', 'bravo']);
    importWithAccounts($accounts, ['server_id' => $this->server->id, 'status' => 'ready', 'account_count' => 2, 'missing' => [], 'proposed_count' => 2,
        'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay(), 'selections' => ['alpha' => ['include' => true], 'bravo' => ['include' => true]]]);
    Queue::fake([ImportReviewedAccounts::class]);
    $digest = $this->get(route('admin.services.servers.import.review', $this->server))->inertiaProps('digest');
    $this->post(route('admin.services.servers.import.apply', $this->server), ['digest' => $digest])->assertSessionHasNoErrors();
    $import = ServerAccountImport::query()->sole();

    // Put it back the way the previous release stored it: the list whole in `accounts`, no rows.
    $rows = $import->accountRows();
    DB::table('server_account_import_accounts')->delete();
    DB::table('server_account_imports')->where('id', $import->id)->update(['accounts' => Crypt::encryptString(json_encode($rows))]);

    (new ImportReviewedAccounts($import->id, auth()->id(), 0, $import->reviewed['digest']))->handle(app(ImportServerAccounts::class));

    expect(Service::query()->count())->toBe(2)
        ->and($import->refresh()->imported_count)->toBe(2);
});

test('[R1] a key rotation re-encrypts the account payloads and keeps the email search working without the old key', function () {
    $use = function (string $current, array $previous = []): void {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Facade::clearResolvedInstance('encrypter');
        app()->forgetInstance(InstallSecrets::class);
    };
    $old = 'base64:'.base64_encode(random_bytes(32));
    $new = 'base64:'.base64_encode(random_bytes(32));
    $use($old);
    importWithAccounts([p453Account('alpha', ['email' => 'victim@example.test']), p453Account('bravo')], ['server_id' => $this->server->id, 'status' => 'ready', 'account_count' => 2, 'missing' => [],
        'unmatched_count' => 2, 'connection_user' => 'root', 'fetched_at' => now(), 'expires_at' => now()->addDay()]);
    $legacy = p453Legacy(Server::factory()->create(), [p453Account('charlie')]);

    $use($new, [$old]);
    Storage::fake('kyc');
    $code = Artisan::call('security:reencrypt');
    // Exit 1 only for the beforeEach server's credentials, written under the test's own APP_KEY.
    expect(Artisan::output())->toMatch('/server_account_import_accounts.payload\s*\|\s*2\s*\|\s*0\s*\|\s*2\s*\|\s*2\s*\|\s*0/');
    $use($new);

    $found = fn (string $term): array => array_column($this->get(route('admin.services.servers.import.show', ['server' => $this->server, 'search' => $term]))->assertOk()->inertiaProps('rows')['data'] ?? [], 'username');
    expect($found('victim@example.test'))->toBe(['alpha'])
        ->and(count($found('')))->toBe(2)
        ->and(array_column($legacy->accountRows() ?: (function () use ($legacy) {
            app(AccountList::class)->adopt($legacy);

            return $legacy->accountRows();
        })(), 'username'))->toBe(['charlie']);
});
