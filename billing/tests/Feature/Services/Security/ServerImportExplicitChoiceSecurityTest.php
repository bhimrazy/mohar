<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use Carbon\CarbonImmutable;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Security re-check of #133 at c793daa8: a flagged match becomes importable only through a request that carries the
 * client_id (a staff decision). [S133E-n]
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: $group->id, driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    $this->anna->forceFill(['email_changed_at' => now()->subDays(3)])->save();
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => Whm::fixture('listaccts_page1')['data']['acct']]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

function s133eAlpha(Server $server): array
{
    return collect(test()->get(route('admin.services.servers.import.review', $server))->assertOk()->inertiaProps('rows'))->keyBy('username')['alpha'];
}

test('[S133E-1] no request without client_id makes the flagged match importable', function (array $choice) {
    expect(collect(ServerAccountImport::query()->sole()->accountRows())->keyBy('username')['alpha']['category'])->toBe('unmatched');

    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true]);
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, ...$choice])->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'cycle' => 'annually']);
    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'client_id' => $this->anna->id])->assertSessionHasErrors('client_id');

    $alpha = s133eAlpha($this->server);
    expect(ServerAccountImport::query()->sole()->selections['alpha'])->not->toHaveKey('client_id')
        ->and($alpha['decision']['flag'])->not->toBeNull()
        ->and($alpha['decision']['problems'])->not->toBeEmpty();
})->with([
    'tick only' => [[]],
    'product only' => [['product_id' => 21]],
    'cycle and date' => [['cycle' => 'annually', 'next_due_date' => '2027-01-15']],
    'client cleared' => [['client_id' => null]],
]);

test('[S133E-2] a request carrying the matched client_id is the explicit choice, and clearing it undoes that', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $this->anna->id])->assertSessionHasNoErrors();
    expect(s133eAlpha($this->server)['decision']['problems'])->toBeEmpty();

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'client_id' => null])->assertSessionHasNoErrors();
    expect(s133eAlpha($this->server)['decision']['problems'])->not->toBeEmpty();
});

test('[S133E-3] staff who cannot view clients cannot confirm a flagged client, only clear a choice', function () {
    actingAsStaff(['services.servers.import']);
    confirmPassword();

    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $this->anna->id])->assertSessionHasErrors('client_id');
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => null])->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections['alpha'])->toBe(['include' => true]);
});

test('[S133E-4] the explicit choice needs the step-up and the select permission', function () {
    actingAsStaff(['core.clients.view']);
    confirmPassword();
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $this->anna->id])->assertForbidden();

    actingAsStaff();
    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp() - 100000]);
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha'], 'include' => true, 'client_id' => $this->anna->id])->assertRedirect();
    expect(ServerAccountImport::query()->sole()->selections)->toBeEmpty();
});
