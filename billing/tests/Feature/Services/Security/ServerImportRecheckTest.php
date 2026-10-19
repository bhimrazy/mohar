<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\FetchAccountsForImport;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security re-check of #87 (step 3a-1): the fixes hold without vacuous passes, and an owner WHM does not report
 * (or reports invalidly) is treated like any owner other than the connection user.
 */
beforeEach(function () {
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->group = ServerGroup::factory()->create();
    $this->kit->priceBook->addProduct(21, 'Basic hosting', driver: 'cpanel', serverGroupId: $this->group->id, driverConfig: ['package' => 'root_basic']);
    $this->victim = Client::factory()->create(['email' => 'victim@example.org']);
    actingAsStaff();
    confirmPassword();
});

function rcServer(array $attributes = []): Server
{
    $server = Server::factory()->create([
        'driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root',
        'api_token' => Whm::TOKEN, 'port' => null, ...$attributes,
    ]);
    test()->group->servers()->attach($server, ['sort_order' => 0]);

    return $server;
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function rcFetch(Server $server, array $rows): array
{
    $rows = array_map(fn (array $row): array => [
        'user' => $row['user'], 'domain' => "{$row['user']}.example.com", 'plan' => 'root_basic', 'owner' => 'root',
        'email' => "{$row['user']}@example.com", 'ip' => '203.0.113.20', 'suspended' => 0, 'is_locked' => 0,
        'suspendreason' => 'not suspended', 'unix_startdate' => 1700000000, 'diskused' => '1M', 'disklimit' => '100M',
        ...$row,
    ], $rows);
    Whm::fake(['listaccts' => ['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'listaccts', 'version' => 1], 'data' => ['acct' => $rows]]]);

    test()->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();

    return collect(ServerAccountImport::query()->where('server_id', $server->id)->sole()->accounts)->keyBy('username')->all();
}

test('invalid usernames are dropped, valid ones kept, and emails are trimmed, lowercased and validated', function () {
    $rows = rcFetch(rcServer(), [
        ['user' => 'alpha', 'email' => '  Victim@Example.ORG '],
        ['user' => 'Evil<b>x</b>'],
        ['user' => str_repeat('a', 33)],
        ['user' => 'ALPHA2'],
        ['user' => "bravo\n"],
        ['user' => 'charlie', 'email' => 'not an email @ really'],
        ['user' => 'delta', 'email' => 'victim@example.org, attacker@example.net'],
        ['user' => 'echo', 'email' => str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61).'.org'],
    ]);

    expect(array_keys($rows))->toBe(['alpha', 'charlie', 'delta', 'echo'])
        ->and($rows['alpha']['email'])->toBe('victim@example.org')
        ->and($rows['alpha']['category'])->toBe('proposed')
        ->and($rows['charlie']['email'])->toBeNull()
        ->and($rows['delta']['email'])->toBeNull()
        ->and($rows['echo']['email'])->toBeNull();
});

test('the suspend reason is capped at 255 characters with control characters removed', function () {
    $rows = rcFetch(rcServer(), [['user' => 'alpha', 'suspended' => 1, 'suspendreason' => "Abuse\x00\x1b[31m\r\n".str_repeat('x', 1000)]]);

    expect(mb_strlen($rows['alpha']['suspend_reason']))->toBeLessThanOrEqual(255)
        ->and($rows['alpha']['suspend_reason'])->toStartWith('Abuse')
        ->and(preg_match('/[\x00-\x1F\x7F]/', $rows['alpha']['suspend_reason']))->toBe(0);
});

test('reading the latest list deletes an expired one and keeps another server\'s', function () {
    $server = rcServer();
    $other = rcServer(['name' => 'Shared 2']);
    ServerAccountImport::query()->create(['server_id' => $server->id, 'status' => 'ready', 'accounts' => [['username' => 'alpha']], 'expires_at' => now()->subMinute()]);
    $kept = ServerAccountImport::query()->create(['server_id' => $other->id, 'status' => 'ready', 'accounts' => [], 'expires_at' => now()->addHour()]);

    expect(FetchAccountsForImport::latest($server))->toBeNull()
        ->and(ServerAccountImport::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('an owner WHM omits or reports invalidly is listed apart, never proposed onto a client', function (mixed $owner) {
    $rows = rcFetch(rcServer(['username' => null]), [['user' => 'alpha', 'owner' => $owner, 'email' => 'victim@example.org']]);

    expect($rows['alpha']['category'])->toBe('reseller')
        ->and($rows['alpha']['client_id'])->toBeNull();
})->with(['missing' => null, 'empty' => '', 'invalid' => 'reseller1;rm -rf', 'too long' => str_repeat('r', 65)]);
