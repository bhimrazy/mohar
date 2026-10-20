<?php

use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\CheckRun;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ClientColumns;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Client import, map and check (client-import spec §5.2–5.4, §6; AC 9, 10, 12, 13, 20–22).
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    actingAsStaff();
    confirmPassword();
});

/**
 * @return array<int, MigrationRecord> by row number
 */
function checkedRows(MigrationRun $run): array
{
    return $run->records()->orderBy('row_number')->get()->keyBy('row_number')->all();
}

test('the import and the admin client form reject the same values', function (string $field, string $value) {
    $valid = ['first_name' => 'Asha', 'last_name' => 'Rai', 'email' => 'asha@example.com', 'company_name' => '', 'phone' => '', 'postcode' => '', 'tax_id' => '', 'notes' => ''];
    $invalid = [...$valid, $field => $value];

    $this->post(route('admin.clients.store'), [...$invalid, 'currency' => 'NPR', 'status' => 'active'])->assertSessionHasErrors($field);

    $header = array_keys($invalid);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([array_values($invalid)], $header)), columns: $header);

    expect(collect(checkedRows($run)[1]->problems)->where('severity', 'error')->pluck('field')->all())->toBe([$field])
        ->and(json_encode(checkedRows($run)[1]->problems))->not->toContain($value ?: '"no value"');
})->with([
    'email' => ['email', 'not-an-email'],
    'first name too long' => ['first_name', str_repeat('a', 101)],
    'company too long' => ['company_name', str_repeat('c', 151)],
    'phone too long' => ['phone', str_repeat('9', 51)],
    'postcode too long' => ['postcode', str_repeat('1', 21)],
    'tax id too long' => ['tax_id', str_repeat('T', 51)],
    'notes too long' => ['notes', str_repeat('n', 5001)],
    'last name missing' => ['last_name', ''],
]);

test('the WHMCS preset suggests targets that staff can change, and a mapping without email is refused', function () {
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')]));

    expect($run->mapping['columns'])->toBe([ClientColumns::EXTERNAL_ID, 'first_name', 'last_name', 'company_name', 'email', 'country', 'currency', 'client_group', 'status']);

    $this->get(route('admin.migration.imports.show', $run))->assertInertia(fn (Assert $page) => $page->component('admin/migration/map')
        ->where('columns.4.header', 'Email Address')->where('columns.4.samples', ['asha@example.com'])
        ->where('suggestions.generic.0', ClientColumns::IMPORTED_FIELD));

    $withoutEmail = [ClientColumns::EXTERNAL_ID, 'first_name', 'last_name', 'company_name', ClientColumns::IMPORTED_FIELD, 'country', 'currency', 'client_group', 'status'];
    $this->put(route('admin.migration.imports.mapping', $run), ['columns' => $withoutEmail, 'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']])
        ->assertSessionHasErrors(['columns' => 'Map a column to each of: email.']);

    $changed = [ClientColumns::IMPORTED_FIELD, ...array_slice($run->mapping['columns'], 1)];
    $run = ClientCsv::check($run, columns: $changed);

    expect($run->status)->toBe(MigrationRun::READY)->and($run->mapping['columns'][0])->toBe(ClientColumns::IMPORTED_FIELD)
        ->and(checkedRows($run)[1]->normalized['extra']['imported_fields'])->toBe([['label' => 'ID', 'value' => '1']]);
});

test('a password column cannot be mapped to anything but Ignore', function () {
    $run = ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com', 'hunter2']], ['First Name', 'Last Name', 'Email', 'Password']));

    $this->put(route('admin.migration.imports.mapping', $run), ['columns' => ['first_name', 'last_name', 'email', ClientColumns::IMPORTED_FIELD],
        'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']])
        ->assertSessionHasErrors(['columns.3' => 'Passwords and card data cannot be imported. Clients set a password from their invitation.']);
});

test('a currency that is not enabled is an error unless mapped, and a blank currency becomes the default', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com', currency: 'GBP'), ClientCsv::row('2', 'b@example.com')])));
    $rows = checkedRows($run);

    expect($rows[1]->status)->toBe('error')->and(collect($rows[1]->problems)->pluck('field')->all())->toBe(['currency'])
        ->and($rows[2]->normalized['defaults']['currency'])->toBe('NPR')->and($rows[2]->normalized['client'])->not->toHaveKey('currency')
        ->and($run->found_values['currencies'])->toBe(['GBP' => 1]);

    $rows = checkedRows(ClientCsv::check($run, ['currencies' => ['GBP' => 'USD']]));
    expect($rows[1]->status)->toBe('ok')->and($rows[1]->normalized['client']['currency'])->toBe('USD');
});

test('an unknown group name blocks the check until staff map it, create it or choose no group', function () {
    $resellers = ClientGroup::query()->create(['name' => 'Resellers']);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com', group: 'resellers'), ClientCsv::row('2', 'b@example.com', group: 'VIP')])));

    expect($run->status)->toBe(MigrationRun::MAPPING)->and($run->found_values['groups'])->toBe(['VIP' => 1]);

    $this->put(route('admin.migration.imports.mapping', $run), ['columns' => $run->mapping['columns'], 'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']])
        ->assertSessionHasErrors(['groups' => 'Choose what to do with the client group that was not found.']);

    $run = ClientCsv::check($run, ['groups' => ['VIP' => 'create']]);
    $rows = checkedRows($run);

    expect($run->status)->toBe(MigrationRun::READY)
        ->and($rows[1]->normalized['client']['client_group_id'])->toBe($resellers->id)
        ->and($rows[2]->normalized['client']['client_group_create'])->toBe('VIP')
        ->and($rows[2]->decision)->toBe('create');
});

test('checking a chunk issues the same number of queries whatever its size', function () {
    $count = function (int $rows): int {
        $run = ClientCsv::upload(ClientCsv::file(array_map(fn (int $i): array => ClientCsv::row((string) $i, "client{$i}@example.com", company: "Company {$i}"), range(1, $rows))));
        $run->forceFill(['status' => MigrationRun::CHECKING, 'generation' => 1])->save();
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new CheckRun($run->id, 1))->withFakeQueueInteractions()->handle();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(150))->toBe($count(15));
});

test('no log record or queued payload carries a cell value while checking', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    Queue::fake();
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'not-an-email'), ClientCsv::row('2', 'tracer.value@example.com', country: 'Narnia')]));
    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));
    $this->put(route('admin.migration.imports.mapping', $run), ['columns' => $run->fresh()->mapping['columns'], 'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']]);
    $job = Queue::pushed(CheckRun::class)->first();
    $job->withFakeQueueInteractions()->handle();

    expect(serialize($job))->not->toContain('tracer.value')
        ->and($run->fresh()->checked_count)->toBe(2)
        ->and(implode("\n", $logged))->not->toContain('tracer.value')->not->toContain('Narnia')
        ->and(json_encode($run->records()->pluck('problems')))->not->toContain('tracer.value')->not->toContain('Narnia')->not->toContain('not-an-email');
});

test('a check from an earlier mapping writes nothing, and saving the mapping a check runs with starts no second check', function () {
    Queue::fake();
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com')]));
    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));
    $mapping = ['columns' => $run->fresh()->mapping['columns'], 'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']];

    $this->put(route('admin.migration.imports.mapping', $run), $mapping)->assertSessionHasNoErrors();
    $this->put(route('admin.migration.imports.mapping', $run), $mapping)->assertSessionHasNoErrors();
    expect(Queue::pushed(CheckRun::class))->toHaveCount(1)->and($run->fresh()->generation)->toBe(1);

    $this->put(route('admin.migration.imports.mapping', $run), [...$mapping, 'options' => [...$mapping['options'], 'on_match' => 'update']]);
    (new CheckRun($run->id, 1))->withFakeQueueInteractions()->handle();

    expect($run->fresh()->generation)->toBe(2)->and($run->fresh()->checked_count)->toBe(0)
        ->and($run->records()->whereNotNull('status')->count())->toBe(0);
});

test('mapping needs the execute permission', function () {
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com')]));
    actingAsStaff(['migration.runs.view']);
    confirmPassword();

    $this->put(route('admin.migration.imports.mapping', $run), ['columns' => $run->mapping['columns'],
        'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']])->assertForbidden();
});
