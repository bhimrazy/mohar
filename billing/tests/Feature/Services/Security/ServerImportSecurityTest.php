<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Support\AccountList;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security review of #87 (Import accounts, step 1), adopted. Account data fetched from WHM is untrusted input: it is
 * validated, encrypted, short-lived, scoped to its server, and can never attach an account to a client on its own.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->group = ServerGroup::factory()->create();
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    $this->victim = Client::factory()->create(['email' => 'victim@example.org']);
});

function secImportServer(array $attributes = []): Server
{
    $server = Server::factory()->create([
        'driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root',
        'api_token' => Whm::TOKEN, 'port' => null, ...$attributes,
    ]);
    test()->group->servers()->attach($server, ['sort_order' => 0]);

    return $server;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function secWhmRow(string $user, array $overrides = []): array
{
    return [
        'user' => $user, 'domain' => "{$user}.example.com", 'plan' => 'root_basic', 'owner' => 'root',
        'email' => "{$user}@example.com", 'ip' => '203.0.113.20', 'suspended' => 0, 'is_locked' => 0,
        'suspendreason' => 'not suspended', 'unix_startdate' => 1700000000, 'diskused' => '1M', 'disklimit' => '100M',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function secFakeAccounts(array $rows, ?string $resolvesTo = Whm::IP): void
{
    Whm::fake(['listaccts' => ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'listaccts', 'version' => 1], 'data' => ['acct' => $rows]]], $resolvesTo);
}

/**
 * @return array<string, array<string, mixed>>
 */
function secRows(Server $server): array
{
    return collect(ServerAccountImport::query()->where('server_id', $server->id)->sole()->accountRows())->keyBy('username')->all();
}

// 1. The permission

test('services.servers.import is sensitive: no module or group wildcard grants it, only an explicit grant or full admin', function () {
    $server = secImportServer();
    secFakeAccounts([secWhmRow('alpha')]);

    expect(app(PermissionRegistry::class)->isSensitive('services.servers.import'))->toBeTrue();

    foreach ([['services.*'], ['services.servers.*'], ['services.servers.view', 'services.servers.manage']] as $permissions) {
        actingAsStaff($permissions);
        confirmPassword();
        $this->post(route('admin.services.servers.import.fetch', $server))->assertForbidden();
        $this->get(route('admin.services.servers.import.show', $server))->assertForbidden();
    }

    expect(ServerAccountImport::query()->count())->toBe(0);

    actingAsStaff(['services.servers.import']);
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();
    expect(ServerAccountImport::query()->sole()->status)->toBe('ready');
});

test('a fetch is audited against the staff member, and the seventh fetch in a minute is throttled', function () {
    $server = secImportServer();
    secFakeAccounts([secWhmRow('alpha')]);
    $staff = actingAsStaff(['services.servers.import']);
    confirmPassword();

    foreach (range(1, 6) as $attempt) {
        $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();
    }

    $this->post(route('admin.services.servers.import.fetch', $server))->assertTooManyRequests();

    expect(ActivityLog::query()->where('subject_id', $server->id)->where('description', 'Accounts on server Shared 1 fetched for import')->pluck('user_id')->unique()->all())
        ->toBe([$staff->id]);
});

// 2. Untrusted account data

test('the stored list is encrypted, hidden from serialisation, scoped to its server, and pruned when it expires', function () {
    $server = secImportServer();
    $other = secImportServer(['name' => 'Shared 2']);
    secFakeAccounts([secWhmRow('alpha', ['email' => 'victim@example.org'])]);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();

    // The contact email is in no column in clear: the payload is ciphertext and the search column a keyed hash.
    $records = DB::table('server_account_import_accounts')->get();
    expect($records)->toHaveCount(1)
        ->and(json_encode($records))->not->toContain('victim@example.org')
        ->and($records[0]->payload)->not->toContain('alpha')->not->toContain('example.org')
        ->and($records[0]->email_hash)->toBe(AccountList::emailHash('victim@example.org'))
        ->and(ServerAccountImport::query()->sole()->toArray())->not->toHaveKey('accounts');

    ServerAccountImport::query()->create(['server_id' => $other->id, 'status' => 'ready', 'expires_at' => now()->subMinute()]);
    $this->get(route('admin.services.servers.import.show', $other))->assertOk()->assertInertia(fn ($page) => $page->where('import', null));
    $this->artisan('automation:run', ['--date' => now()->toDateString(), '--only' => 'services.import-prune', '--force' => true])->assertSuccessful();

    expect(ServerAccountImport::query()->pluck('server_id')->all())->toBe([$server->id]);
});

test('usernames and contact emails WHM reports are validated before they are stored or matched', function () {
    $server = secImportServer();
    secFakeAccounts([
        secWhmRow('alpha'),
        secWhmRow('Evil<b>x</b>'),
        secWhmRow(str_repeat('a', 200)),
        secWhmRow('bravo', ['email' => 'not an email @ really']),
        secWhmRow('charlie', ['email' => 'victim@example.org, attacker@example.net']),
    ]);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();
    $accounts = ServerAccountImport::query()->sole()->accountRows();

    foreach ($accounts as $account) {
        expect($account['username'])->toMatch('/^[a-z0-9][a-z0-9_.-]{0,31}$/')
            ->and($account['email'] === null || filter_var($account['email'], FILTER_VALIDATE_EMAIL) !== false)->toBeTrue();
    }
});

test('account values are passed to the page as plain text and cannot break out of the page data', function () {
    $server = secImportServer();
    secFakeAccounts([secWhmRow('alpha', ['suspended' => 1, 'suspendreason' => '</script><img src=x onerror=alert(1)>'])]);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();

    $rows = collect($this->get(route('admin.services.servers.import.show', $server))->inertiaProps('rows.data'))->keyBy('username');
    expect($rows['alpha']['suspend_reason'])->toBe('</script><img src=x onerror=alert(1)>');
    $this->withoutVite()->get(route('admin.services.servers.import.show', $server))->assertOk()
        ->assertDontSee('</script><img src=x', false);
});

test('a contact email that matches a client only proposes; a closed client is never proposed, and nothing is created', function () {
    $server = secImportServer();
    $closed = Client::factory()->create(['email' => 'closed@example.org', 'status' => 'closed']);
    secFakeAccounts([secWhmRow('alpha', ['email' => 'Victim@Example.org']), secWhmRow('bravo', ['email' => 'closed@example.org'])]);
    actingAsStaff();
    confirmPassword();
    $clients = Client::query()->count();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();
    $rows = secRows($server);

    expect($rows['alpha']['category'])->toBe('proposed')
        ->and($rows['alpha']['client_id'])->toBe($this->victim->id)
        ->and($rows['bravo']['category'])->toBe('unmatched')
        ->and($rows['bravo']['client_id'])->toBeNull()
        ->and(Client::query()->count())->toBe($clients)
        ->and(Service::query()->count())->toBe(0);
});

// 3. Reseller-owned accounts

test('a root connection saved without a username (the driver signs in as root) still lists reseller-owned accounts apart', function () {
    $server = secImportServer(['username' => null]);
    secFakeAccounts([secWhmRow('alpha'), secWhmRow('bravo', ['owner' => 'reseller1', 'email' => 'victim@example.org'])]);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();

    expect(secRows($server)['bravo']['category'])->toBe('reseller')
        ->and(secRows($server)['bravo']['client_id'])->toBeNull();
});

test('on a reseller connection, the reseller\'s own accounts are classified and anything owned by another user is listed apart', function () {
    $server = secImportServer(['username' => 'reseller1']);
    secFakeAccounts([
        secWhmRow('alpha', ['owner' => 'reseller1', 'email' => 'victim@example.org']),
        secWhmRow('reseller1', ['owner' => 'root']),
    ]);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();
    $rows = secRows($server);

    expect($rows['alpha']['category'])->toBe('proposed')
        ->and($rows['reseller1']['category'])->toBe('reseller');
});

// 4. Failed fetch messages

test('a failed fetch masks the credentials, raw and encoded, in the stored error, on the page and in what is reported', function (Closure $answer) {
    $server = secImportServer();
    Exceptions::fake();
    Whm::fake(['listaccts' => $answer()]);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();
    $import = ServerAccountImport::query()->sole();

    expect($import->status)->toBe('failed')
        ->and($import->error)->toStartWith('The accounts on Shared 1 could not be read:')
        ->not->toContain(Whm::TOKEN)->not->toContain(rtrim(base64_encode(Whm::TOKEN), '='))->not->toContain(rawurlencode(Whm::TOKEN));

    $this->get(route('admin.services.servers.import.show', $server))->assertDontSee(Whm::TOKEN, false);
    expect(Exceptions::reported())->not->toBeEmpty();

    foreach (Exceptions::reported() as $reported) {
        expect($reported->getMessage())->not->toContain(Whm::TOKEN)->not->toContain(rtrim(base64_encode(Whm::TOKEN), '='));
    }
})->with([
    'connection error naming the token' => fn () => fn () => Http::failedConnection('cURL error 7: token '.Whm::TOKEN.' / '.base64_encode(Whm::TOKEN)),
    'WHM refusal echoing the token' => fn () => fn () => ['metadata' => ['result' => 0, 'reason' => 'Access denied for token '.Whm::TOKEN.' ('.rawurlencode(Whm::TOKEN).')', 'command' => 'listaccts', 'version' => 1]],
    'server error body echoing the token' => fn () => fn () => Http::response('<html>500 '.Whm::TOKEN.' '.base64_encode(Whm::TOKEN).'</html>', 500),
]);

// 5. The fetch goes through the driver's guarded client

test('a server host that resolves to a loopback, link-local or metadata address is never contacted', function (string $address) {
    $server = secImportServer();
    secFakeAccounts([secWhmRow('alpha')], resolvesTo: $address);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();

    expect(ServerAccountImport::query()->sole()->status)->toBe('failed');
    Http::assertNothingSent();
})->with(['127.0.0.1', '::1', '169.254.169.254', '168.63.129.16', '0.0.0.0']);

test('a private address is refused when the install-wide policy refuses private addresses', function (string $address) {
    config(['integrations.outbound.allow_private_addresses' => false]);
    $server = secImportServer();
    secFakeAccounts([secWhmRow('alpha')], resolvesTo: $address);
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.services.servers.import.fetch', $server))->assertRedirect();

    expect(ServerAccountImport::query()->sole()->status)->toBe('failed');
    Http::assertNothingSent();
})->with(['10.0.0.5', '192.168.1.10', 'fd00::1']);
