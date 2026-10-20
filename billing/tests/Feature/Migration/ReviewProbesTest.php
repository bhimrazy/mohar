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
 * Review-board probes for #161-#163 (client import upload, map, check). Each reproduces a finding from the review;
 * adopt them into ClientImportUploadTest / ClientImportCheckTest when fixing.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    actingAsStaff();
    confirmPassword();
});

// B1: RowNormalizer seeds tax_exempt, currency, status and language with defaults, and ClientMatcher::changes()
// compares them even when no column maps them, so an update would clear tax exemption, re-label currency and
// reactivate inactive clients. §8: "update of the mapped fields only".
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

// N2: a merged match resolves to the survivor, and the update then proposes overwriting the survivor's email with
// the merged client's old address.
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

// B2 (security, must-fix): the column limit is checked on the header only. A data row of 2 MB of separators is
// accepted and costs about 125 MB to parse (measured); 4 MB exhausts the 256 MB request, and an accepted row breaks the
// map page (samples() decodes 20 raw rows) and pushes import jobs past their memory budget.
test('probe B2: a row with more cells than the column limit is refused at upload and nothing is stored', function () {
    $file = ClientCsv::file([['Asha', 'Rai', 'asha@example.com', ...array_fill(0, 300, '')]], ['First Name', 'Last Name', 'Email']);

    $this->post(route('admin.migration.imports.store'), ['file' => $file, 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors('file');

    expect(Storage::disk('objects')->allFiles())->toBe([])->and(MigrationRun::query()->count())->toBe(0);
});

// N3: encoding is checked on the first 64 KB only; a Windows-1252 byte later is silently replaced by U+FFFD at staging
// (JSON_INVALID_UTF8_SUBSTITUTE), so "José" is imported as "Jos\u{FFFD}" without any warning.
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
        ->assertSessionHasErrors(['file' => 'This file is not UTF-8 text. Save the file as CSV UTF-8 and upload it again.']);
});

// N4: StageCsvRun deletes the file before it records the end; a crash between the two leaves file_key pointing at a
// deleted object, every retry and every "Continue" throws "no longer stored", and the run can never reach the map step.
test('probe N4: a staging job that finds every row staged and the file gone finishes the run', function () {
    Queue::fake();
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]));
    $key = (string) $run->file_key;
    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));
    // The state a crash after Storage::delete() and before the final save leaves behind.
    $run->refresh()->forceFill(['status' => MigrationRun::STAGING, 'file_key' => $key])->save();

    (new StageCsvRun($run->id))->handle(app(ActivityLogger::class));

    expect($run->refresh()->status)->toBe(MigrationRun::MAPPING)->and($run->file_key)->toBeNull();
});

// N5: purgeExpired() uses each(), which pages by offset while the callback removes rows from the filtered set
// (purged_at is set), so with more than 1,000 expired runs every second page is skipped.
test('probe N5: the purge reaches every expired run, even more than a thousand', function () {
    $source = MigrationSource::query()->create(['driver' => 'csv', 'name' => 'Old']);
    $rows = array_map(fn (int $i): array => ['migration_source_id' => $source->id, 'source_kind' => 'csv', 'entity' => 'clients',
        'status' => MigrationRun::MAPPING, 'file_name' => "f{$i}.csv", 'file_sha256' => str_repeat('a', 64), 'file_bytes' => 1,
        'delimiter' => ',', 'expires_at' => now()->subDay(), 'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)], range(1, 1200));
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('migration_runs')->insert($chunk);
    }

    $this->artisan('automation:run', ['--only' => 'migration.purge'])->assertSuccessful();

    expect(MigrationRun::query()->whereNull('purged_at')->count())->toBe(0);
});

// Verified: every write route needs migration.runs.execute (the existing tests cover only the upload).
test('probe: mapping and continue need the execute permission', function () {
    $run = ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com')]));
    actingAsStaff(['migration.runs.view']);
    confirmPassword();

    $this->put(route('admin.migration.imports.mapping', $run), ['columns' => $run->mapping['columns'],
        'options' => ['on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import']])->assertForbidden();
    $this->post(route('admin.migration.imports.resume', $run))->assertForbidden();
    $this->get(route('admin.migration.imports.show', $run))->assertOk();
});
