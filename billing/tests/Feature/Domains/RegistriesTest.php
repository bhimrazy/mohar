<?php

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Jobs\RegisterDomain;
use App\Modules\Domains\Jobs\RenewDomain;
use App\Modules\Domains\Jobs\SyncRegistrars;
use App\Modules\Domains\Jobs\TransferDomain;
use App\Modules\Domains\Models\Domain;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Navigation\Navigation;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Queues;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Domains\DomainFixtures;

beforeEach(function () {
    $this->com = DomainFixtures::com('log');
    $this->travelTo(CarbonImmutable::parse('2026-10-17'));
});

test('admin navigation shows the Domains group by permission', function () {
    $staff = actingAsStaff(['domains.domains.view', 'domains.tlds.view']);

    $groups = collect(app(Navigation::class)->forUser('admin', $staff))->keyBy('title');

    expect(collect($groups['Domains']['items'])->pluck('title')->all())->toBe(['Domains', 'TLD pricing']);
});

test('client navigation links to my domains and the store\'s domain search', function () {
    [$user] = actingAsClient();

    $items = collect(app(Navigation::class)->forUser('client', $user))->firstWhere('title', 'Domains')['items'];

    expect(collect($items)->pluck('href')->all())->toBe([route('client.domains.index'), route('store.domains')]);
});

test('the client profile links to the client domains', function () {
    $staff = actingAsStaff(['domains.domains.view']);

    expect(app(Navigation::class)->clientLinks(5, $staff))->toContain(['title' => 'Domains', 'href' => route('admin.domains.index', ['client' => 5]), 'icon' => 'Globe']);
});

test('dashboard cards count expiring and pending domains', function () {
    $staff = actingAsStaff(['domains.domains.view']);
    Domain::factory()->expiring('2026-11-01')->create(['tld_id' => $this->com->id]);
    Domain::factory()->expiring('2027-06-01')->create(['tld_id' => $this->com->id]);
    Domain::factory()->pending()->create(['tld_id' => $this->com->id]);
    Domain::factory()->pending()->status(DomainStatus::PendingTransfer)->create(['tld_id' => $this->com->id]);

    $cards = collect(app(Dashboard::class)->resolve('admin', $staff))->keyBy('key');

    expect($cards['domains.expiring']['value'])->toBe(1)
        ->and($cards['domains.pending']['value'])->toBe(2);
});

test('the client dashboard card counts only the client domains', function () {
    [$user, $client] = actingAsClient();
    Domain::factory()->expiring('2026-10-30')->create(['tld_id' => $this->com->id, 'client_id' => $client->id]);
    Domain::factory()->expiring('2026-10-30')->create(['tld_id' => $this->com->id]);

    $card = collect(app(Dashboard::class)->resolve('client', $user, $client))->firstWhere('key', 'domains.expiring');

    expect($card['value'])->toBe(1);
});

test('global search finds domains case-insensitively for permitted staff', function () {
    $domain = Domain::factory()->named('findme', $this->com)->create();

    $results = app(GlobalSearch::class)->search('FINDME', actingAsStaff(['domains.domains.view']));
    expect(collect($results)->firstWhere('group', 'Domains')['results'][0]['href'])->toBe(route('admin.domains.show', $domain));

    expect(collect(app(GlobalSearch::class)->search('findme', actingAsStaff(['support.*'])))->firstWhere('group', 'Domains'))->toBeNull();
});

test('domain merge fields include the client id and dates', function () {
    $domain = Domain::factory()->expiring('2026-11-16')->create(['tld_id' => $this->com->id]);

    expect(app(MergeFieldRegistry::class)->resolve('domain', $domain->id))->toMatchArray([
        'domain_id' => $domain->id,
        'client_id' => $domain->client_id,
        'domain' => $domain->name,
        'expiry_date' => '2026-11-16',
        'days_until_expiry' => 30,
    ]);
});

test('every checked permission and tunable setting is registered', function () {
    $permissions = app(PermissionRegistry::class);
    foreach (['domains.domains.view', 'domains.domains.update', 'domains.domains.commands', 'domains.domains.delete', 'domains.tlds.view', 'domains.tlds.manage', 'domains.registrars.manage'] as $permission) {
        expect($permissions->exists($permission))->toBeTrue();
    }

    $sections = app(SettingsRegistry::class)->sections();
    $registered = collect($sections)->filter(fn (array $section, string $key): bool => str_starts_with($key, 'domains'))
        ->flatMap(fn (array $section) => collect($section['settings'])->pluck('key'))
        // The default contact is edited field by field.
        ->map(fn (string $key): string => str_starts_with($key, 'domains.default_contact.') ? 'domains.default_contact' : $key)->all();
    $defaults = array_map(fn (string $key): string => "domains.{$key}", array_keys(config('domains')));

    // The cart route is a developer setting, left out of the UI on purpose.
    expect(array_values(array_diff($defaults, $registered)))->toBe(['domains.order_route']);
});

test('needs-attention todos count expiring and failed domains', function () {
    $staff = actingAsStaff(['domains.domains.view']);
    Domain::factory()->expiring('2026-11-01')->create(['tld_id' => $this->com->id]);
    Domain::factory()->pending()->create(['tld_id' => $this->com->id, 'last_error' => 'Rejected']);
    Domain::factory()->create(['tld_id' => $this->com->id, 'needs_review' => true]);

    $todos = collect(app(Dashboard::class)->resolveTodos('admin', $staff))->keyBy('key');

    expect($todos['domains.expiring']['count'])->toBe(1)
        ->and($todos['domains.attention']['count'])->toBe(2);
});

test('registrar sync has one owner: the hourly schedule, not the daily pipeline', function () {
    $sync = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'domains:sync');

    expect(app(Automation::class)->ordered())->not->toHaveKey('domains.sync')
        ->and($sync)->toHaveCount(1)
        ->and($sync->first()->expression)->toBe('0 * * * *')
        ->and($sync->first()->command)->toBeNull();
});

test('registrar work runs on the registrar queue, never in the scheduler process (ADR 0014, 0017)', function () {
    Queue::fake();

    collect(app(Schedule::class)->events())->firstWhere('description', 'domains:sync')->run(app());
    RegisterDomain::dispatch(1);
    TransferDomain::dispatch(2);
    RenewDomain::dispatch(3, 1, '2027-01-31');

    foreach ([SyncRegistrars::class, RegisterDomain::class, TransferDomain::class, RenewDomain::class] as $job) {
        Queue::assertPushedOn(Queues::REGISTRAR, $job);
    }
});

test('the registrar health check reports the 7-day command success rate', function () {
    $staff = actingAsStaff(['domains.*']);
    $log = fn (bool $success) => DB::table('registrar_command_log')->insert(['registrar_key' => 'sandbox', 'command' => 'register', 'success' => $success, 'created_at' => now()]);
    $check = fn () => collect(app(Dashboard::class)->resolveHealth('admin', $staff))->firstWhere('key', 'domains.registrars');

    expect($check())->toMatchArray(['value' => 'Idle', 'tone' => 'neutral']);

    $log(true);
    $log(false);

    expect($check())->toMatchArray(['value' => '50%', 'tone' => 'warning', 'detail' => '1 of 2 succeeded (7 days)']);
});
