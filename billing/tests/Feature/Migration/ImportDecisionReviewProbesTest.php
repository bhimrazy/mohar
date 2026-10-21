<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Migration\Support\ReviewDigest;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Review-board probes for PR #201 (client import, row decisions and report). Each one fails on 4f28b846 and passes
 * once the finding it is named after is fixed. The link tests cover the CTO rule of 2026-10-21 (spec §6.11).
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    actingAsStaff();
    confirmPassword();
});

test('probe B5: "update" on a company-name match never replaces that client\'s email, nor its owner login\'s', function () {
    $ram = Client::factory()->create(['email' => 'ram@himalweb.example', 'company_name' => 'Himal Web Pvt Ltd']);
    $login = User::factory()->create(['email' => 'ram@himalweb.example']);
    $ram->users()->attach($login, ['is_owner' => true]);

    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'someone@elsewhere.example', company: 'Himal Web Pvt. Ltd.')])), ['match_company' => true]);
    expect($run->decision_count)->toBe(1);

    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'update'])->assertSessionHasNoErrors();
    ClientCsv::apply($run);

    expect($login->fresh()->email)->toBe('ram@himalweb.example')
        ->and($ram->fresh()->email)->toBe('ram@himalweb.example');
});

test('probe M3: the errors CSV, which carries the raw cells of every failed row, asks for a recent password', function () {
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'not-an-email')])));

    $this->withSession(['auth.password_confirmed_at' => 0])->get(route('admin.migration.imports.errors', $run))->assertRedirect();
});

test('a row whose email belongs to a long-verified login offers link or no link; linking makes that login the owner and moves the digest', function () {
    $login = User::factory()->create(['email' => 'asha@example.com', 'email_verified_at' => now()->subDays(40)]);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')])));
    $digest = $run->review_digest;

    $this->get(route('admin.migration.imports.show', $run))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.decision', 'needs_decision')->where('rows.data.0.choices', ['link', 'no_link', 'skip'])->where('rows.data.0.link_login', null));
    $this->post(route('admin.migration.imports.accept', $run))->assertRedirect();
    expect($run->fresh()->decision_count)->toBe(1);

    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'link'])->assertSessionHasNoErrors();
    $run->refresh();
    $record = $run->records()->sole();
    expect($record->decision)->toBe('create')->and($record->link_login)->toBeTrue()
        ->and($run->create_count)->toBe(1)->and($run->decision_count)->toBe(0)->and($run->review_digest)->not->toBe($digest);
    // The posted digest must be the current one, and the stored row hashes rebuild to it.
    $this->post(route('admin.migration.imports.apply', $run), ['digest' => $digest])->assertSessionHasErrors('apply');
    expect(ReviewDigest::of($run))->toBe($run->review_digest);

    ClientCsv::apply($run);
    $client = Client::query()->where('email', 'asha@example.com')->sole();
    expect($client->users()->wherePivot('is_owner', true)->sole()->id)->toBe($login->id)
        ->and(User::query()->count())->toBe(2);
});

test('a login verified less than 30 days ago offers only "no link"; "link" is refused with the reason, and the client is created without a login', function () {
    $login = User::factory()->create(['email' => 'asha@example.com', 'email_verified_at' => now()->subDays(5)]);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')])));

    $this->get(route('admin.migration.imports.show', $run))->assertInertia(fn (Assert $page) => $page->where('rows.data.0.choices', ['no_link', 'skip']));
    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'link'])->assertSessionHasErrors(['decision' => 'Row 1 cannot be set to that.']);
    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'no_link'])->assertSessionHasNoErrors();

    $run = ClientCsv::apply($run->fresh());
    $client = Client::query()->where('email', 'asha@example.com')->sole();

    expect($run->records()->sole())->decision->toBe('create')->link_login->toBeFalse()->outcome->toBe('created')
        ->and($client->users()->count())->toBe(0)
        ->and($login->fresh()->clients()->count())->toBe(0)
        ->and(array_column($run->records()->sole()->problems, 'code'))->toContain('login_not_linked');
});
