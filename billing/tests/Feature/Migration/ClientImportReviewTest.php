<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Client import, review and report (client-import spec §5.5, §5.7, §6.13; AC 16, 31–33).
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

test('accepting all proposals creates the possible duplicates and leaves a recent email change for staff, with a count', function () {
    Client::factory()->create(['company_name' => 'Acme Pvt Ltd', 'email' => 'owner@acme.example']);
    $moved = Client::factory()->create(['email' => 'moved@example.com']);
    $moved->forceFill(['email_changed_at' => now()->subDays(10)])->saveQuietly();
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'new@acme.example', company: 'ACME Pvt. Ltd.'), ClientCsv::row('2', 'moved@example.com')])), ['match_company' => true]);
    $digest = $run->review_digest;

    $this->post(route('admin.migration.imports.accept', $run))->assertRedirect()
        ->assertInertiaFlash('toast.message', '1 proposed row accepted. 1 row is left for you: its match is closed, shared by several clients or changed email recently.');
    $run->refresh();

    expect($run->records()->where('row_number', 1)->value('decision'))->toBe('create')
        ->and($run->records()->where('row_number', 2)->value('decision'))->toBe('needs_decision')
        ->and($run->decision_count)->toBe(1)->and($run->review_digest)->not->toBe($digest);
});

test('staff decide a row among its allowed choices, and the review digest follows', function () {
    Client::factory()->create(['email' => 'asha@example.com', 'first_name' => 'Asha', 'last_name' => 'Old', 'country' => 'NP', 'company_name' => null, 'status' => 'active']);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')])));
    $digest = $run->review_digest;

    $this->get(route('admin.migration.imports.show', $run))->assertInertia(fn (Assert $page) => $page->where('rows.data.0.decision', 'skip')->where('rows.data.0.choices', ['update', 'skip']));
    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'create'])->assertSessionHasErrors(['decision' => 'Row 1 cannot be set to that.']);
    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'update'])->assertSessionHasNoErrors();

    $record = $run->records()->sole();
    expect($record->decision)->toBe('update')->and($record->changes)->toBe(['last_name'])
        ->and($run->fresh())->update_count->toBe(1)->skip_count->toBe(0)->review_digest->not->toBe($digest);

    actingAsStaff(['migration.runs.view']);
    confirmPassword();
    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'skip'])->assertForbidden();
});

test('the report lists each row by outcome with a link to its client, and imported fields show on the profile', function () {
    $header = [...ClientCsv::HEADER, 'Reseller Code'];
    $run = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'a@example.com'), 'R-17'], [...ClientCsv::row('2', 'bad'), '']], $header))));
    $client = Client::query()->where('email', 'a@example.com')->sole();

    $this->get(route('admin.migration.imports.show', [$run, 'tab' => 'failed']))->assertInertia(fn (Assert $page) => $page->component('admin/migration/check')
        ->where('rows.total', 1)->where('rows.data.0.row', 2)->where('rows.data.0.outcome', 'failed'));
    $this->get(route('admin.migration.imports.show', [$run, 'tab' => 'created']))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.outcome', 'created')->where('rows.data.0.client_href', route('admin.clients.show', $client))
        ->where('rows.data.0', fn ($row): bool => array_intersect(array_keys($row->all()), ['id', 'target_id', 'match_client_id', 'migration_run_id']) === []));
    $this->get(route('admin.clients.show', $client))->assertInertia(fn (Assert $page) => $page
        ->where('imported.fields', [['label' => 'Reseller Code', 'value' => 'R-17', 'source' => 'Old billing']]));
});

test('the errors CSV escapes formula cells, names the column and is stored nowhere', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', '=HYPERLINK("http://evil")', first: '+Asha')])));

    $response = $this->get(route('admin.migration.imports.errors', $run))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $lines = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", $response->streamedContent())));

    expect($lines[0])->toBe(['Row', 'Column', 'Reason', ...ClientCsv::HEADER])
        ->and($lines[1][0])->toBe('1')->and($lines[1][1])->toBe('Email Address')
        ->and($lines[1][4])->toBe("'+Asha")->and($lines[1][7])->toBe('\'=HYPERLINK("http://evil")')
        ->and(Storage::disk('objects')->allFiles())->toBe([]);

    actingAsStaff(['migration.runs.view']);
    $this->get(route('admin.migration.imports.errors', $run))->assertForbidden();
});
