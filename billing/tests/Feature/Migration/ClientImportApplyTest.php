<?php

use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Enums\ImportAction;
use App\Events\Core\ClientRegistered;
use App\Events\Migration\MigrationRunCompleted;
use App\Models\User;
use App\Modules\Core\Enums\ClientOrigin;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Import\ClientImporter;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientMarketingConsent;
use App\Modules\Core\Models\Currency;
use App\Modules\Migration\Models\MigrationIdMap;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\SourceIds;
use App\Support\Migration\ImportRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Migration\ClientCsv;

/**
 * Client import, confirm and apply (client-import spec §5.6, §6, §8, §9; AC 23–30).
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

/** Throws once, on the second row it sees, to stand for a worker dying mid-chunk. */
final class FlakyClientImporter extends ClientImporter
{
    public static int $calls = 0;

    public function upsert(ImportRecord $record, ImportContext $context, ?int $existingTargetId): ImportOutcome
    {
        if (++self::$calls === 2) {
            throw new RuntimeException('Worker died.');
        }

        return parent::upsert($record, $context, $existingTargetId);
    }
}

test('confirming needs the execute permission, a recent password and the reviewed digest', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')])));

    actingAsStaff(['migration.runs.view']);
    $this->post(route('admin.migration.imports.apply', $run), ['digest' => $run->review_digest])->assertForbidden();
    actingAsStaff(['migration.runs.view', 'migration.runs.execute']);
    $this->withSession(['auth.password_confirmed_at' => 0])->post(route('admin.migration.imports.apply', $run), ['digest' => $run->review_digest])
        ->assertSessionHasErrors('password_confirmation');

    confirmPassword();
    $run->records()->where('row_number', 2)->update(['decision' => 'skip']);
    $this->post(route('admin.migration.imports.apply', $run), ['digest' => $run->review_digest])
        ->assertSessionHasErrors(['apply' => 'The rows changed since you reviewed them. Review the import again.']);

    expect($run->fresh()->status)->toBe(MigrationRun::READY)->and(Client::query()->count())->toBe(0);
});

test('applying creates imported clients and owner logins that cannot sign in, and sends nothing', function () {
    Event::fake([ClientRegistered::class, MigrationRunCompleted::class]);
    Mail::fake();
    Notification::fake();
    $header = [...ClientCsv::HEADER, 'Date Created', 'Newsletter'];
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([
        [...ClientCsv::row('1', 'asha@example.com', group: 'Gold'), '2024-03-15', 'yes'],
        [...ClientCsv::row('2', 'old@example.com', status: 'Closed'), '2021-01-02', ''],
    ], $header)), ['groups' => ['Gold' => 'create']], dateFormat: 'Y-m-d');
    $run = ClientCsv::apply($run);

    $asha = Client::query()->where('email', 'asha@example.com')->sole();
    $login = $asha->users()->sole();

    expect($run->status)->toBe(MigrationRun::COMPLETED)->and($run->created_count)->toBe(2)
        ->and($asha->origin)->toBe(ClientOrigin::Import)->and($asha->email_source)->toBe(EmailSource::Import)
        ->and($asha->imported_at)->not->toBeNull()->and($asha->created_at->toDateString())->toBe('2024-03-15')
        ->and($asha->group->name)->toBe('Gold')
        ->and($login->pivot->is_owner)->toBeTruthy()->and($login->email_verified_at)->toBeNull()
        ->and(Hash::check('password', $login->password))->toBeFalse()
        ->and(Client::query()->where('email', 'old@example.com')->sole())->status->toBe('closed')->closed_at->not->toBeNull()
        ->and(User::query()->where('email', 'old@example.com')->exists())->toBeFalse()
        ->and(MigrationIdMap::query()->where('source_id', '1')->value('target_id'))->toBe($asha->id);

    auth()->logout();
    $this->post('/login', ['email' => 'asha@example.com', 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => 'asha@example.com', 'password' => 'password'])->assertSessionHasErrors();
    $this->assertGuest();

    Event::assertNotDispatched(ClientRegistered::class);
    Event::assertDispatchedTimes(MigrationRunCompleted::class, 1);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('a re-run with an External ID updates a changed email and the owner login only when it never signed in', function () {
    $source = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]))))->migration_source_id;
    User::query()->where('email', 'b@example.com')->update(['last_login_at' => now()]);
    $this->travel(1)->hours();

    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a.new@example.com'), ClientCsv::row('2', 'b.new@example.com')]), ['source_id' => $source]));
    expect($run->update_count)->toBe(2);
    $run = ClientCsv::apply($run);
    $rows = $run->records()->orderBy('row_number')->get();

    expect(Client::query()->find($rows[0]->target_id))->email->toBe('a.new@example.com')->email_changed_at->not->toBeNull()
        ->and(User::query()->where('email', 'a.new@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'b@example.com')->exists())->toBeTrue()
        ->and(array_column($rows[1]->problems, 'code'))->toContain('login_email_kept')
        ->and($rows[0]->changes)->toBe(['email']);
});

test('a re-run of the same file is unchanged everywhere and leaves updated_at alone', function () {
    $file = fn () => ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]);
    $source = ClientCsv::apply(ClientCsv::check(ClientCsv::upload($file())))->migration_source_id;
    $stamps = Client::query()->orderBy('id')->pluck('updated_at')->map->toIso8601String()->all();
    $this->travel(1)->hours();

    $run = ClientCsv::check(ClientCsv::upload($file(), ['source_id' => $source]));

    expect($run->unchanged_count)->toBe(2)->and($run->create_count + $run->update_count)->toBe(0)
        ->and(Client::query()->orderBy('id')->pluck('updated_at')->map->toIso8601String()->all())->toBe($stamps);
});

test('a client added by hand after the review makes that row fail; the other rows apply', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')])));
    Client::factory()->create(['email' => 'b@example.com']);

    $run = ClientCsv::apply($run);
    $failed = $run->records()->where('row_number', 2)->sole();

    expect($run->created_count)->toBe(1)->and($run->failed_count)->toBe(1)
        ->and($failed->outcome)->toBe('failed')
        ->and(array_column($failed->problems, 'message'))->toContain('A client with this email was added after the review. Nothing was created.')
        ->and(Client::query()->where('email', 'b@example.com')->count())->toBe(1);
});

test('a chunk that stops halfway is continued without creating any client, login or id-map row twice', function () {
    FlakyClientImporter::$calls = 0;
    app(ImportRegistry::class)->register('client', FlakyClientImporter::class, replace: true);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')])));

    $this->post(route('admin.migration.imports.apply', $run), ['digest' => $run->review_digest])->assertServerError();
    expect($run->fresh()->status)->toBe(MigrationRun::FAILED)->and(Client::query()->count())->toBe(1);

    $this->post(route('admin.migration.imports.resume', $run))->assertRedirect();

    expect($run->fresh())->status->toBe(MigrationRun::COMPLETED)->created_count->toBe(3)
        ->and(Client::query()->count())->toBe(3)->and(User::query()->where('is_staff', false)->count())->toBe(3)
        ->and(MigrationIdMap::query()->count())->toBe(3);
});

test('an email that belongs to a staff login creates the client without a login, with a note', function () {
    User::factory()->staff(['*'])->create(['email' => 'ops@example.com']);
    $run = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'ops@example.com')]))));
    $client = Client::query()->where('email', 'ops@example.com')->sole();

    expect($client->users()->count())->toBe(0)
        ->and(array_column($run->records()->sole()->problems, 'code'))->toContain('staff_login');
});

test('consent: yes without a date is granted with that evidence, blank records nothing, and a withdrawal here is kept', function () {
    $header = [...ClientCsv::HEADER, 'Newsletter'];
    $source = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'a@example.com'), 'yes'], [...ClientCsv::row('2', 'b@example.com'), '']], $header))));
    $a = Client::query()->where('email', 'a@example.com')->sole();
    $consent = ClientMarketingConsent::query()->where('client_id', $a->id)->sole();

    expect($consent->state)->toBe('granted')->and($consent->consented_at)->toBeNull()->and($consent->source)->toBe('import_csv')
        ->and($consent->evidence)->toContain('the date of consent was not in the file')
        ->and(ClientMarketingConsent::query()->count())->toBe(1);

    ClientMarketingConsent::query()->create(['client_id' => $a->id, 'state' => 'withdrawn', 'source' => 'client', 'recorded_at' => now()]);
    $run = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'a@example.com', last: 'Changed'), 'yes']], $header), ['source_id' => $source->migration_source_id])));

    expect(ClientMarketingConsent::currentState($a->id))->toBe('withdrawn')
        ->and(array_column($run->records()->sole()->problems, 'code'))->toContain('consent_kept');
});

test('tax-exempt rows need their own acknowledgement', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([[...ClientCsv::row('1', 'a@example.com'), 'Yes']], [...ClientCsv::HEADER, 'Tax Exempt'])));

    $this->post(route('admin.migration.imports.apply', $run), ['digest' => $run->review_digest])
        ->assertSessionHasErrors(['tax_exempt' => 'Confirm that the tax-exempt clients will be invoiced without tax.']);
    expect(ClientCsv::apply($run, ['tax_exempt' => '1'])->created_count)->toBe(1)
        ->and(Client::query()->sole()->tax_exempt)->toBeTrue();
});

test('an update changes only mapped fields and keeps a merge survivor\'s email', function () {
    $kept = Client::factory()->create(['email' => 'asha@example.com', 'first_name' => 'Asha', 'last_name' => 'Old', 'currency' => 'USD', 'status' => 'inactive', 'tax_exempt' => true]);
    $survivor = Client::factory()->create(['email' => 'kept@example.com', 'first_name' => 'Bikash', 'last_name' => 'Old']);
    Client::factory()->create(['email' => 'merged@example.com', 'status' => 'closed', 'merged_into_client_id' => $survivor->id]);
    Currency::factory()->create(['code' => 'USD', 'is_default' => false]);
    $header = ['First Name', 'Last Name', 'Email'];

    $run = ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([['Asha', 'Rai', 'asha@example.com'], ['Bikash', 'Thapa', 'merged@example.com']], $header)),
        ['on_match' => 'update'], ['first_name', 'last_name', 'email']));

    expect($run->updated_count)->toBe(2)
        ->and($kept->fresh())->last_name->toBe('Rai')->currency->toBe('USD')->status->toBe('inactive')->tax_exempt->toBeTrue()
        ->and($survivor->fresh())->last_name->toBe('Thapa')->email->toBe('kept@example.com');
});

test('a dry run of the client importer writes nothing and says what it would do', function () {
    $client = Client::factory()->create(['email' => 'asha@example.com', 'last_name' => 'Old']);
    $context = new ImportContext(1, 1, 'csv', true, now()->toImmutable(), null, new SourceIds(1));
    $importer = app(ImportRegistry::class)->get('client');

    $created = $importer->upsert(new ImportRecord('client', 'csv', '9', ['first_name' => 'B', 'last_name' => 'C', 'email' => 'new@example.com', 'currency' => 'NPR', 'status' => 'active']), $context, null);
    $updated = $importer->upsert(new ImportRecord('client', 'csv', '1', ['last_name' => 'Rai']), $context, $client->id);

    expect($created->action)->toBe(ImportAction::Created)->and($updated->action)->toBe(ImportAction::Updated)->and($updated->changes)->toBe(['last_name'])
        ->and(Client::query()->count())->toBe(1)->and($client->fresh()->last_name)->toBe('Old');
});

test('a password reset reaches an imported login only through its own mailbox', function () {
    ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')]))));
    auth()->logout();

    $this->post(route('password.email'), ['email' => 'asha@example.com'])->assertSessionHasNoErrors();

    expect(DB::table('password_reset_tokens')->where('email', 'asha@example.com')->exists())->toBeTrue();
});
