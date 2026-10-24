<?php

use App\Enums\BillingCycle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Models\Service;
use Inertia\Testing\AssertableInertia as Assert;
use Mohar\Simulators\Providers\Whm\WhmSimulator;
use Tests\Simulators\SimulatorHarness;
use Tests\TestCase;

uses(TestCase::class, SimulatorHarness::class);

/**
 * Importing a host's existing cPanel accounts end to end (cPanel spec §10, ADR 0016 §4), against the whm simulator
 * over TLS: fetch, match, choose a billing cycle, ignore, review, import (including an account suspended on the
 * server), take the server's changed values into a service, and Match again. Every module is real; WHM is the
 * simulator. Nothing in the import writes to WHM, which the simulator's log shows.
 */
beforeEach(function () {
    $this->startSimulators();
});

afterEach(function () {
    $this->stopSimulators();
});

test('a host brings its WHM accounts in: fetch, ignore, bill on a chosen cycle, import, accept the server\'s changes, match again', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Currency::factory()->npr()->create();
    actingAsStaff();
    confirmPassword();

    fwrite(STDERR, "  › a cPanel server trusted through Test connection, a product on root_basic priced monthly and annually in NPR\n");
    $server = Server::factory()->create(['driver' => 'cpanel', 'name' => 'Simulated WHM', 'hostname' => '127.0.0.1', 'port' => $this->whm->apiPort(), 'username' => 'root', 'api_token' => WhmSimulator::ROOT_TOKEN]);
    $this->post(route('admin.services.servers.test', $server));
    $this->post(route('admin.services.servers.trust-certificate', $server), ['fingerprint' => app('session.store')->get('inertia.flash_data.connectionCheck.details.certificate_spki_sha256')])->assertSessionHasNoErrors();
    $group = ServerGroup::factory()->create();
    $group->servers()->attach($server, ['sort_order' => 0]);
    $product = Product::factory()->withPrice('NPR', BillingCycle::Monthly, 150_050)->withPrice('NPR', BillingCycle::Annually, 1_500_500)
        ->create(['name' => 'Shared Basic', 'provisioning_driver' => 'cpanel', 'driver_config' => ['package' => 'root_basic'], 'server_group_id' => $group->id]);
    $anna = Client::factory()->create(['email' => 'owner@alpha-books.test', 'currency' => 'NPR']);
    $ram = Client::factory()->create(['email' => 'ram@example.test', 'currency' => 'NPR']);

    fwrite(STDERR, "  › accounts made on WHM outside the app: charlie (Ram's), delta (a test account), echo (suspended on the server)\n");
    $add = $this->whm->simulator()->actions()['add_account'];
    $add(['user' => 'charlie', 'domain' => 'charlie-shop.test', 'plan' => 'root_basic', 'email' => 'ram@example.test'], $this->whm);
    $add(['user' => 'delta', 'domain' => 'delta-test.test', 'plan' => 'root_basic', 'email' => 'nobody@example.test'], $this->whm);
    $add(['user' => 'echo', 'domain' => 'echo-news.test', 'plan' => 'root_basic', 'email' => 'ram@example.test'], $this->whm);
    $this->whm->state()->put('accounts', 'echo', [...$this->whm->state()->get('accounts', 'echo'), 'suspended' => 1, 'suspendreason' => 'bandwidth exceeded']);

    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
    $page = fn (array $query = []) => $this->get(route('admin.services.servers.import.show', [$server, ...$query]))->assertOk();
    $rows = fn (array $query = []) => collect($page($query)->inertiaProps('rows.data'))->keyBy('username');

    expect($rows()->map->category->all())->toBe(['alpha' => 'proposed', 'bravo' => 'reseller', 'charlie' => 'proposed', 'delta' => 'unmatched', 'echo' => 'proposed'])
        ->and($rows()['alpha']['client']['id'])->toBe($anna->id);

    fwrite(STDERR, "  › the dialog offers the product's priced cycles; delta is ignored and stays ignored after a second fetch\n");
    $page()->assertInertia(fn (Assert $inertia) => $inertia->where("pricedCycles.{$product->id}.NPR", ['monthly', 'annually']));
    $this->put(route('admin.services.servers.import.ignore', $server), ['usernames' => ['delta'], 'ignored' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();
    expect($rows()['delta']['category'])->toBe('ignored')
        ->and(ServerAccountImport::query()->sole()->unmatched_count)->toBe(0);

    fwrite(STDERR, "  › alpha, charlie and echo are ticked, charlie is billed annually; the review is confirmed and imported\n");
    $this->put(route('admin.services.servers.import.select', $server), ['proposed' => true])->assertSessionHasNoErrors();
    $this->put(route('admin.services.servers.import.select', $server), ['usernames' => ['charlie'], 'include' => true, 'cycle' => 'annually'])->assertSessionHasNoErrors();
    $review = $this->get(route('admin.services.servers.import.review', $server))->assertOk();
    $this->post(route('admin.services.servers.import.apply', $server), ['digest' => $review->inertiaProps('digest')])->assertSessionHasNoErrors();

    $services = Service::query()->orderBy('username')->get()->keyBy('username');
    expect($services->keys()->all())->toBe(['alpha', 'charlie', 'echo'])
        ->and($services['alpha'])->status->toBe(ServiceStatus::Active)->cycle->toBe(BillingCycle::Monthly)->recurring_amount->toBe(150_050)
        ->and($services['charlie'])->cycle->toBe(BillingCycle::Annually)->recurring_amount->toBe(1_500_500)
        ->and($services['echo'])->status->toBe(ServiceStatus::Suspended)
        ->and($services['echo']->suspension_reason)->toContain('suspended on the server: bandwidth exceeded')
        ->and(ServerAccountImport::query()->sole()->only(['imported_count', 'not_imported_count', 'linked_count']))->toBe(['imported_count' => 3, 'not_imported_count' => 0, 'linked_count' => 3])
        ->and($this->whm->log()->count('createacct'))->toBe(0);

    fwrite(STDERR, "  › on WHM alpha's domain changes and echo is unsuspended; fetch again, then accept both into the services\n");
    $this->whm->state()->put('accounts', 'alpha', [...$this->whm->state()->get('accounts', 'alpha'), 'domain' => 'alpha-books-new.test']);
    $this->whm->state()->put('accounts', 'echo', [...$this->whm->state()->get('accounts', 'echo'), 'suspended' => 0, 'suspendreason' => 'not suspended']);
    $this->post(route('admin.services.servers.import.fetch', $server))->assertSessionHasNoErrors();

    $linked = $rows(['tab' => 'linked']);
    expect(array_column($linked['alpha']['accept'], 'field'))->toBe(['domain'])
        ->and(array_column($linked['echo']['accept'], 'field'))->toBe(['status']);

    foreach (['alpha' => 'domain', 'echo' => 'status'] as $username => $field) {
        $change = $linked[$username]['accept'][0];
        $this->post(route('admin.services.servers.import.accept', $server), ['username' => $username, 'fields' => [$field], 'digest' => $change['digest']])->assertSessionHasNoErrors();
    }

    expect($services['alpha']->refresh()->domain)->toBe('alpha-books-new.test')
        ->and($services['echo']->refresh()->status)->toBe(ServiceStatus::Active)
        ->and($rows(['tab' => 'linked'])->pluck('accept')->flatten(1)->all())->toBe([])
        ->and($this->whm->log()->count('suspendacct'))->toBe(0)
        ->and($this->whm->log()->count('unsuspendacct'))->toBe(0);

    fwrite(STDERR, "  › a client is added for delta's address; Match again keeps delta ignored and re-matches the rest without calling WHM\n");
    Client::factory()->create(['email' => 'nobody@example.test', 'currency' => 'NPR']);
    $calls = $this->whm->log()->count('listaccts');
    $this->post(route('admin.services.servers.import.rematch', $server))->assertSessionHasNoErrors();

    expect($rows()['delta']['category'])->toBe('ignored')
        ->and($this->whm->log()->count('listaccts'))->toBe($calls);
});
