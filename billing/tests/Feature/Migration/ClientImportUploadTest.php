<?php

use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Client import, upload and staging (client-import spec §5.1, §5.8, §12; AC 4–11). AC 9's mapping half (a forbidden
 * column cannot be mapped) and AC 10's checking half are in ClientImportCheckTest.php.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

test('uploading needs the execute permission and a recent password; viewing needs the view permission', function () {
    actingAsStaff(['migration.runs.view']);
    $this->get(route('admin.migration.imports.index'))->assertOk();
    $this->post(route('admin.migration.imports.store'), ['file' => ClientCsv::file([ClientCsv::row('1', 'a@example.com')]), 'source_name' => 'Old', 'preset' => 'generic'])->assertForbidden();

    actingAsStaff(['migration.runs.view', 'migration.runs.execute']);
    $this->withSession(['auth.password_confirmed_at' => 0])->post(route('admin.migration.imports.store'), ['file' => ClientCsv::file([ClientCsv::row('1', 'a@example.com')]), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors('password_confirmation');

    actingAsStaff([]);
    $this->get(route('admin.migration.imports.index'))->assertForbidden();
    expect(MigrationRun::query()->count())->toBe(0);
});

test('a file over 20 MB or over 100,000 rows is refused with the limit, and nothing is stored', function () {
    $this->post(route('admin.migration.imports.store'), ['file' => UploadedFile::fake()->create('big.csv', 21 * 1024, 'text/csv'), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors(['file' => 'The file is larger than 20 MB, the limit per file.']);

    $rows = array_fill(0, 100_001, ['a', 'b', 'c@example.com']);
    $this->post(route('admin.migration.imports.store'), ['file' => ClientCsv::file($rows, ['First Name', 'Last Name', 'Email']), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors(['file' => 'The file has more than 100,000 rows. Split it into smaller files.']);

    expect(Storage::disk('objects')->allFiles())->toBe([])->and(MigrationRun::query()->count())->toBe(0);
});

test('a UTF-16 file is refused with the CSV UTF-8 hint; a UTF-8 file with a BOM and semicolons is staged with its headers', function () {
    $utf16 = tempnam(sys_get_temp_dir(), 'utf16');
    file_put_contents($utf16, "\xFF\xFE".mb_convert_encoding("First Name,Last Name,Email\nAsha,Rai,asha@example.com\n", 'UTF-16LE', 'UTF-8'));
    $this->post(route('admin.migration.imports.store'), ['file' => new UploadedFile($utf16, 'clients.csv', 'text/csv', null, true), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors(['file' => 'This file is not UTF-8 text. Save the file as CSV UTF-8 and upload it again.']);

    $run = ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com'], ['Bikash', 'Thapa', 'bikash@example.com']], ['First Name', 'Last Name', 'Email'], ';', "\xEF\xBB\xBF"));

    expect($run->delimiter)->toBe(';')
        ->and($run->header)->toBe(['First Name', 'Last Name', 'Email'])
        ->and($run->status)->toBe(MigrationRun::MAPPING)
        ->and($run->records()->orderBy('row_number')->first()->raw)->toBe(['Asha', 'Rai', 'asha@example.com']);
});

test('the stored upload is encrypted, and staging deletes it and stages every data row', function () {
    Queue::fake();
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('7', 'secret.person@example.com'), ClientCsv::row('8', 'other@example.com')]));
    $stored = Storage::disk('objects')->get((string) $run->file_key);

    expect($stored)->not->toContain('secret.person@example.com')->not->toContain('Asha')
        ->and(Queue::pushed(StageCsvRun::class))->toHaveCount(1)
        ->and(serialize(Queue::pushed(StageCsvRun::class)->first()))->not->toContain('secret.person');

    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));
    $run->refresh();

    expect(Storage::disk('objects')->allFiles())->toBe([])
        ->and($run->file_key)->toBeNull()
        ->and($run->row_count)->toBe(2)
        ->and($run->records()->count())->toBe(2);
});

test('password and card columns are never stored, and the map step says so', function () {
    $run = ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com', 'hunter2-pass', '4111111111111111']], ['First Name', 'Last Name', 'Email', 'Password', 'Card number']));

    expect(json_encode($run->records()->get()->pluck('raw')))->not->toContain('hunter2')->not->toContain('4111');

    $this->get(route('admin.migration.imports.show', $run))->assertInertia(fn (Assert $page) => $page->component('admin/migration/map')
        ->where('columns.2.samples', ['asha@example.com'])
        ->where('columns.3.forbidden', true)->where('columns.3.samples', [])
        ->where('columns.4.forbidden', true));
});

test('no log record or queued payload carries a cell value while staging', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'not-an-email'), ClientCsv::row('2', 'tracer.value@example.com', country: 'Narnia')]));

    expect($run->status)->toBe(MigrationRun::MAPPING)
        ->and(implode("\n", $logged))->not->toContain('tracer.value')->not->toContain('Narnia');
});

test('an unapplied run is purged when it expires: rows lose their payloads and the counts remain', function () {
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]));

    $this->travel(8)->days();
    $this->artisan('automation:run', ['--only' => 'migration.purge'])->assertSuccessful();
    $run->refresh();

    expect($run->status)->toBe(MigrationRun::EXPIRED)
        ->and($run->purged_at)->not->toBeNull()
        ->and($run->row_count)->toBe(2)
        ->and(MigrationRecord::query()->where('migration_run_id', $run->id)->where(fn ($query) => $query->whereNotNull('raw')->orWhereNotNull('normalized'))->count())->toBe(0);
});
