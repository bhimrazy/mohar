<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Models\MigrationSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Review-board probes for #161-#163 (client import upload, map, check), adopted as regression tests. Each reproduces
 * a finding of the review on review/pr-161-163-probes.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    actingAsStaff();
    confirmPassword();
});

// B2 (security): the column limit was checked on the header only; a row of separators cost about 125 MB per 2 MB.
test('probe B2: a row with more cells than the column limit is refused at upload and nothing is stored', function () {
    $file = ClientCsv::file([['Asha', 'Rai', 'asha@example.com', ...array_fill(0, 300, '')]], ['First Name', 'Last Name', 'Email']);

    $this->post(route('admin.migration.imports.store'), ['file' => $file, 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors(['file' => 'Row 1 has more cells than the 200 columns a file may have.']);

    expect(Storage::disk('objects')->allFiles())->toBe([])->and(MigrationRun::query()->count())->toBe(0);
});

test('probe B2: a line longer than the read limit is refused instead of being split into rows', function () {
    $path = tempnam(sys_get_temp_dir(), 'import-probe-');
    file_put_contents($path, "First Name,Last Name,Email\nAsha,Rai,".str_repeat('x', 300 * 1024)."\n");

    $this->post(route('admin.migration.imports.store'), ['file' => new UploadedFile($path, 'clients.csv', 'text/csv', null, true), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors('file');
    expect(MigrationRun::query()->count())->toBe(0);
});

// N3: encoding was checked on the first 64 KB only, and a later Windows-1252 byte was silently replaced.
test('probe N3: invalid UTF-8 after the first 64 KB is refused, not silently replaced', function () {
    $path = tempnam(sys_get_temp_dir(), 'import-probe-');
    $handle = fopen($path, 'wb');
    fwrite($handle, "First Name,Last Name,Email\n");
    foreach (range(1, 2500) as $i) {
        fwrite($handle, "Asha,Rai,client{$i}@example.com\n");
    }
    fwrite($handle, "Jos\xE9,Rai,jose@example.com\n");
    fclose($handle);

    $this->post(route('admin.migration.imports.store'), ['file' => new UploadedFile($path, 'clients.csv', 'text/csv', null, true), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors(['file' => 'Row 2,501 is not UTF-8 text. Save the file as CSV UTF-8 and upload it again.']);
});

// N4: a crash between deleting the file and recording the end left a run that could never finish.
test('probe N4: a staging job that finds every row staged and the file gone finishes the run', function () {
    Queue::fake();
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]));
    $key = (string) $run->file_key;
    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));
    $run->refresh()->forceFill(['status' => MigrationRun::STAGING, 'file_key' => $key])->save();

    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));

    expect($run->refresh()->status)->toBe(MigrationRun::MAPPING)->and($run->file_key)->toBeNull();
});

// N5: the purge paged by offset while it removed runs from the filter, and skipped some.
test('probe N5: the purge reaches every expired run, even more than a thousand', function () {
    $source = MigrationSource::query()->create(['source_system' => 'csv', 'name' => 'Old']);
    $rows = array_map(fn (int $i): array => ['migration_source_id' => $source->id, 'source_system' => 'csv', 'entity' => 'client',
        'status' => MigrationRun::MAPPING, 'file_name' => "f{$i}.csv", 'file_sha256' => str_repeat('a', 64), 'file_bytes' => 1,
        'delimiter' => ',', 'expires_at' => now()->subDay(), 'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)], range(1, 1200));
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('migration_runs')->insert($chunk);
    }

    $this->artisan('automation:run', ['--only' => 'migration.purge'])->assertSuccessful();

    expect(MigrationRun::query()->whereNull('purged_at')->count())->toBe(0);
});

test('probe: Continue needs the execute permission, and two clicks start one job', function () {
    Queue::fake();
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com')]));
    $run->forceFill(['status' => MigrationRun::FAILED])->save();

    actingAsStaff(['migration.runs.view']);
    confirmPassword();
    $this->post(route('admin.migration.imports.resume', $run))->assertForbidden();

    actingAsStaff(['migration.runs.view', 'migration.runs.execute']);
    confirmPassword();
    $this->post(route('admin.migration.imports.resume', $run))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('admin.migration.imports.resume', $run))->assertSessionHasErrors(['run' => 'This import is not stopped.']);

    expect(Queue::pushed(StageCsvRun::class))->toHaveCount(2);
});

// B1 (money): defaults for unmapped fields were compared, so a name-and-email file would clear tax exemption,
// re-label currency and reactivate a client. §8: "update of the mapped fields only".
test('probe B1: an update compares only mapped fields; unmapped tax exemption, currency and status are not changes', function () {
    Client::factory()->create(['email' => 'asha@example.com', 'first_name' => 'Asha', 'last_name' => 'Rai',
        'currency' => 'USD', 'status' => 'inactive', 'tax_exempt' => true, 'language' => 'en']);
    $header = ['First Name', 'Last Name', 'Email'];
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com']], $header)),
        ['on_match' => 'update'], ['first_name', 'last_name', 'email']);
    $record = $run->records()->firstOrFail();

    expect($record->changes)->toBe([])
        ->and($record->decision)->not->toBe('update')
        ->and($run->tax_exempt_count)->toBe(0);
});

// N2: a match followed to the merge survivor proposed replacing the survivor's email with the merged client's.
test('probe N2: a match followed to the merge survivor does not propose replacing the survivor\'s email', function () {
    $survivor = Client::factory()->create(['email' => 'kept@example.com', 'first_name' => 'Asha', 'last_name' => 'Rai', 'status' => 'active']);
    Client::factory()->create(['email' => 'merged@example.com', 'status' => 'closed', 'merged_into_client_id' => $survivor->id]);
    $header = ['First Name', 'Last Name', 'Email'];
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'merged@example.com']], $header)),
        ['on_match' => 'update'], ['first_name', 'last_name', 'email']);
    $record = $run->records()->firstOrFail();

    expect($record->match_client_id)->toBe($survivor->id)
        ->and($record->changes)->not->toContain('email');
});
