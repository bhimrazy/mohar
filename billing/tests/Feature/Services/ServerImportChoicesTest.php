<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use Carbon\CarbonImmutable;
use Tests\Fakes\Services\ServicesTestKit;
use Tests\Support\Cpanel\Whm;

/**
 * Import accounts, step 3b-2: choosing per account and in bulk, the client picker, choices kept across "Fetch again",
 * and proposed matches whose client changed email recently.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    Currency::factory()->create();
    $this->kit = ServicesTestKit::bind();
    $this->server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Shared 1', 'hostname' => Whm::HOST, 'username' => 'root', 'api_token' => Whm::TOKEN, 'port' => null]);
    ServerGroup::factory()->create()->servers()->attach($this->server, ['sort_order' => 0]);
    $this->kit->priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200, 'annually' => 12000], driver: 'cpanel', serverGroupId: ServerGroup::query()->value('id'), driverConfig: ['package' => 'root_basic']);
    $this->anna = Client::factory()->create(['email' => 'anna@alpha-books.com', 'currency' => 'USD']);
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => [...Whm::fixture('listaccts_page1')['data']['acct'], ...Whm::fixture('listaccts_page2')['data']['acct']]]])]);
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
});

test('a changed email is recorded on the client and on the login', function () {
    $user = User::factory()->create();

    $this->anna->update(['email' => 'anna@new.example']);
    $user->update(['email' => 'login@new.example']);

    expect($this->anna->refresh()->email_changed_at?->toDateTimeString())->toBe('2026-10-19 10:00:00')
        ->and($user->refresh()->email_changed_at?->toDateTimeString())->toBe('2026-10-19 10:00:00');
});

test('Select all proposed leaves out a match whose client changed email in the last 30 days, and the row says so', function () {
    $this->anna->forceFill(['email_changed_at' => now()->subDays(3)])->save();

    $this->put(route('admin.services.servers.import.select', $this->server), ['proposed' => true])
        ->assertSessionHas('inertia.flash_data.toast.message', '1 proposed accounts were not ticked: their client changed email in the last 30 days. Check them one by one.');

    $alpha = collect($this->get(route('admin.services.servers.import.show', $this->server))->inertiaProps('rows.data'))->keyBy('username')['alpha'];
    expect(ServerAccountImport::query()->sole()->selections)->toBeEmpty()
        ->and($alpha['note'])->toContain('This client changed email on 2026-10-16');
});

test('the cycle and due date can be set for every ticked account at once, and choices survive Fetch again', function () {
    $this->put(route('admin.services.servers.import.select', $this->server), ['usernames' => ['alpha', 'charlie'], 'include' => true])->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $this->server), ['ticked' => true, 'cycle' => 'annually', 'next_due_date' => '2027-02-01'])->assertSessionHasNoErrors();

    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();
    Whm::fake(['listaccts' => Whm::fixture('listaccts_example', ['data' => ['acct' => Whm::fixture('listaccts_page1')['data']['acct']]])]);
    $this->post(route('admin.services.servers.import.fetch', $this->server))->assertSessionHasNoErrors();

    expect(ServerAccountImport::query()->sole()->selections)->toBe(['alpha' => ['include' => true, 'cycle' => 'annually', 'next_due_date' => '2027-02-01']]);
});

test('the client picker lists active clients, the most recently added first before anything is typed', function () {
    Client::factory()->create(['status' => 'closed']);
    $newest = Client::factory()->create(['first_name' => 'Zed']);

    $listed = $this->getJson(route('admin.services.servers.import.clients', $this->server))->assertOk()->json();

    expect($listed[0]['id'])->toBe($newest->id)
        ->and(collect($listed)->pluck('id')->all())->toBe([$newest->id, $this->anna->id])
        ->and($this->getJson(route('admin.services.servers.import.clients', [$this->server, 'q' => 'alpha-books']))->json('0.id'))->toBe($this->anna->id);
});
