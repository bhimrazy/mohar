<?php

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Models\User;
use App\Modules\Core\Import\ClientImporter;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Domains\Models\Domain;
use App\Modules\Migration\Support\ReviewDigest;
use App\Modules\Orders\Models\Order;
use App\Modules\Services\Models\Service;
use App\Support\Clients\ClientCurrencyUsage;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Re-review probes for PR #184 at f94c3bb9 (adopted from the re-review), and the fix-round tests for the same findings.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    actingAsStaff();
    confirmPassword();
});

test('re-review B3: an update never relabels the currency of a client that already has a service (spec 6.4 lists services)', function () {
    $client = Client::factory()->create(['email' => 'asha@example.com', 'currency' => 'NPR']);
    Service::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);

    ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com', last: 'Changed', currency: 'USD')])), ['on_match' => 'update']));

    expect($client->fresh()->currency)->toBe('NPR');
});

test('B3: a client with only an order keeps its currency, and the check says why', function () {
    $client = Client::factory()->create(['email' => 'asha@example.com', 'currency' => 'NPR']);
    Order::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);

    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com', last: 'Changed', currency: 'USD')])), ['on_match' => 'update']);
    expect(array_column($run->records()->sole()->problems, 'code'))->toContain('currency_locked');

    ClientCsv::apply($run);

    expect($client->fresh()->currency)->toBe('NPR');
});

test('B3: a service added between the check and the import still keeps the currency (the importer asks again)', function () {
    $client = Client::factory()->create(['email' => 'asha@example.com', 'currency' => 'NPR']);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com', last: 'Changed', currency: 'USD')])), ['on_match' => 'update']);
    expect($run->update_count)->toBe(1);

    Service::factory()->create(['client_id' => $client->id, 'currency' => 'NPR']);
    $record = ClientCsv::apply($run)->records()->sole();

    expect($client->fresh())->currency->toBe('NPR')->last_name->toBe('Changed')
        ->and(array_column($record->problems, 'code'))->toContain('currency_kept');
});

test('B3: every module that stores amounts per client answers through the kernel registry, a deleted service or domain included', function () {
    $usage = app(ClientCurrencyUsage::class);
    [$none, $service, $order, $domain] = Client::factory()->count(4)->create()->all();
    Service::factory()->create(['client_id' => $service->id])->delete();
    Order::factory()->create(['client_id' => $order->id]);
    Domain::factory()->create(['client_id' => $domain->id])->delete();

    expect($usage->keys())->toEqualCanonicalizing(['billing', 'services', 'orders', 'domains'])
        ->and($usage->clientsInUse([$none->id, $service->id, $order->id, $domain->id]))->toEqualCanonicalizing([$service->id, $order->id, $domain->id])
        ->and($usage->clientsInUse([]))->toBe([]);
});

test('B2: the undo snapshot is what the importer overwrote under its lock, so an admin edit just before it is what undo restores', function () {
    $source = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com')]))))->migration_source_id;
    $this->travel(1)->hours();
    confirmPassword();
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com', last: 'Changed')]), ['source_id' => $source]));

    // An admin edit commits after anything Migration could read and before the importer takes its row lock.
    $this->app->bind(ClientImporter::class, fn () => new class extends ClientImporter
    {
        public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
        {
            Client::query()->whereKey($existingTargetId)->update(['last_name' => 'Admin']);

            return parent::upsert($record, $context, $existingTargetId);
        }
    });
    $record = ClientCsv::apply($run)->records()->sole();

    expect(Client::query()->findOrFail($record->target_id)->last_name)->toBe('Changed')
        ->and($record->changes)->toBe(['last_name'])
        ->and($record->before)->toBe(['client' => ['last_name' => 'Admin']]);
});

test('re-review kernel hook: staff who change their own email on the staff page must verify the new address before returning to the admin area (#388)', function () {
    $me = actingAsStaff();
    confirmPassword();

    $this->put(route('admin.platform.staff.update', $me), ['name' => $me->name, 'email' => 'me.new@example.com', 'role_id' => $me->role_id, 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect($me->fresh())->email->toBe('me.new@example.com')->email_verified_at->toBeNull();
    $this->actingAs($me->fresh())->get(route('admin.migration.imports.index'))->assertRedirect();
});

test('kernel hook: an admin who changes another staff member\'s email still clears its verification and sends the mail', function () {
    Notification::fake();
    $colleague = User::factory()->staff(['support.*'])->create(['email' => 'old@example.com']);

    $this->put(route('admin.platform.staff.update', $colleague), ['name' => $colleague->name, 'email' => 'new@example.com', 'role_id' => $colleague->role_id, 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect($colleague->fresh())->email->toBe('new@example.com')->email_verified_at->toBeNull();
    Notification::assertSentTo($colleague->fresh(), VerifyEmail::class);
});

test('kernel hook: a client login\'s email change clears its verification on every path, unless the same save sets it', function () {
    $login = User::factory()->create(['email' => 'asha@example.com', 'email_verified_at' => now()->subYear()]);

    $login->forceFill(['email' => 'asha.new@example.com'])->save();
    expect($login->fresh()->email_verified_at)->toBeNull();

    $login->forceFill(['email' => 'asha.again@example.com', 'email_verified_at' => now()])->save();
    expect($login->fresh()->email_verified_at)->not->toBeNull();

    $login->forceFill(['name' => 'Asha R'])->save();
    expect($login->fresh()->email_verified_at)->not->toBeNull();
});

test('re-review digest: a malformed row hash counts as zeros instead of throwing', function () {
    $zeros = str_repeat('0', 64);

    expect(ReviewDigest::replace(null, str_repeat('z', 64), null))->toBe($zeros)
        ->and(ReviewDigest::replace(null, 'abc', null))->toBe($zeros)
        ->and(ReviewDigest::replace(str_repeat('g', 64), null, str_repeat('a', 64)))->toBe(str_repeat('a', 64));
});
