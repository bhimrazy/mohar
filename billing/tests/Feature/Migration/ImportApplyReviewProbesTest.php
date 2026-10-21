<?php

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportRecord;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\ApplyChunk;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\SourceIds;
use App\Support\Migration\ImportRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Review-board probes for PR #184 (client import, confirm and apply). Each one fails on fe27bd37 and passes once the
 * finding it is named after is fixed.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

// Adopted with the CTO rule (2026-10-21): an import never links an existing login on its own. The row needs a
// decision, and linking is allowed only for a login whose email was verified at least 30 days ago.
test('probe B1: a client login whose owner set its email to a customer\'s address makes that row need a decision, and is never linked', function () {
    // Anyone with a client login can set its email to any address, unverified (settings/profile).
    $mallory = User::factory()->create(['email' => 'mallory@example.com']);
    $this->actingAs($mallory)->patch(route('profile.update'), ['name' => 'Mallory', 'email' => 'asha@example.com'])->assertSessionHasNoErrors();
    expect($mallory->fresh())->email->toBe('asha@example.com')->email_verified_at->toBeNull();

    actingAsStaff();
    confirmPassword();
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')])));
    $record = $run->records()->sole();

    // Her address is unverified, so the row cannot even be linked: only "don't link" or skip.
    expect($record->decision)->toBe('needs_decision')
        ->and(array_column($record->problems, 'code'))->toContain('existing_login_recent')->not->toContain('existing_login');
    $this->post(route('admin.migration.imports.apply', $run), ['digest' => $run->review_digest])
        ->assertSessionHasErrors(['apply' => 'Decide what to do with the row that needs a decision first.']);
    expect(Client::query()->where('email', 'asha@example.com')->exists())->toBeFalse();
});

test('probe B1: an existing login is linked only when staff chose it and its email was verified 30 days ago or more', function () {
    $context = new ImportContext(1, 1, 'csv', false, now()->toImmutable(), null, new SourceIds(1));
    $importer = app(ImportRegistry::class)->get('user');
    $link = fn (User $login, bool $chosen): ?int => $importer->upsert(new ImportRecord('user', 'csv', (string) $login->id,
        ['email' => $login->email, 'name' => 'X', 'link_existing' => $chosen], ['client_id' => Client::factory()->create()->id]), $context, null)->targetId;

    $fresh = User::factory()->create(['email_verified_at' => now()->subDays(29)]);
    $unverified = User::factory()->create(['email_verified_at' => null]);
    $settled = User::factory()->create(['email_verified_at' => now()->subDays(31)]);

    expect($link($fresh, true))->toBeNull()
        ->and($link($unverified, true))->toBeNull()
        ->and($link($settled, false))->toBeNull()
        ->and($link($settled, true))->toBe($settled->id)
        ->and(User::query()->count())->toBe(4);
});

test('probe B2: Continue on a stopped import does not start it while another import is being applied', function () {
    $first = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com')])));
    $first->forceFill(['status' => MigrationRun::FAILED, 'applied_at' => now(), 'applied_by' => auth()->id(), 'heartbeat_at' => now()])->save();
    $second = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'b@example.com')], name: 'second.csv')));
    $second->forceFill(['status' => MigrationRun::APPLYING, 'applied_at' => now(), 'applied_by' => auth()->id(), 'heartbeat_at' => now()])->save();

    $this->post(route('admin.migration.imports.resume', $first))->assertSessionHasErrors();

    expect($first->fresh()->status)->toBe(MigrationRun::FAILED)->and(Client::query()->count())->toBe(0);
});

test('probe B3: an update never relabels the currency of a client that already has invoices (spec 6.4)', function () {
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    $client = Client::factory()->create(['email' => 'asha@example.com', 'currency' => 'NPR']);
    Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);

    ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com', last: 'Changed', currency: 'USD')])), ['on_match' => 'update']));

    expect($client->fresh()->currency)->toBe('NPR');
});

test('probe M1: an email-matched update does not put back an email the client changed after the review', function () {
    $client = Client::factory()->create(['email' => 'asha@example.com', 'last_name' => 'Old']);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')])), ['on_match' => 'update']);
    expect($run->update_count)->toBe(1);

    // The customer moves to a new address before staff confirm.
    $client->forceFill(['email' => 'asha.new@example.com'])->save();
    ClientCsv::apply($run);

    expect($client->fresh()->email)->toBe('asha.new@example.com');
});

test('probe M2: a second ApplyChunk for the same run (a redelivered or resumed job) creates no client twice', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')])));
    $nested = false;

    // Stand-in for a second worker: once the first job has loaded its pending rows, another job for the run runs to the end.
    DB::listen(function (QueryExecuted $query) use (&$nested, $run): void {
        if (! $nested && str_contains($query->sql, 'lower(email) in')) {
            $nested = true;
            (new ApplyChunk($run->id))->handle(app(ImportRegistry::class), app(ActivityLogger::class), app(Settings::class));
        }
    });

    ClientCsv::apply($run);

    expect($nested)->toBeTrue()
        ->and(Client::query()->count())->toBe(3)
        ->and(MigrationIdMap::query()->count())->toBe(3);
});

test('probe L1: a dry run of the client importer creates no client group', function () {
    $context = new ImportContext(1, 1, 'csv', true, now()->toImmutable(), null, new SourceIds(1));

    app(ImportRegistry::class)->get('client')->upsert(new ImportRecord('client', 'csv', '9', [
        'first_name' => 'B', 'last_name' => 'C', 'email' => 'new@example.com', 'currency' => 'NPR', 'status' => 'active', 'client_group_name' => 'Brand new group',
    ]), $context, null);

    expect(ClientGroup::query()->count())->toBe(0);
});
