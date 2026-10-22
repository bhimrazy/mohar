<?php

use App\Contracts\Auth\ClientLoginInvitations;
use App\Enums\InvitationOutcome;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\NullClientLoginInvitations;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Client import, "Send invitations" (client-import spec §5.7, §16 C3 and C9; AC 3, 35, 36).
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    actingAsStaff();
    confirmPassword();
});

/**
 * A completed import of the given rows (the queue runs synchronously).
 *
 * @param  list<list<string>>  $rows
 * @param  array<string, mixed>  $options
 */
function importedRun(array $rows, array $options = []): MigrationRun
{
    return ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file($rows)), $options));
}

/** The invitation links mailed to an address. */
function invitationTokens(string $email): array
{
    $tokens = [];
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($email, &$tokens): bool {
        if ($mail->hasTo($email) && preg_match('#/account-invitation/([A-Za-z0-9]+)#', $mail->plainText, $match)) {
            $tokens[] = $match[1];
        }

        return true;
    });

    return $tokens;
}

test('the page counts the imported logins without a password, by state and filter, and needs the view permission', function () {
    ClientGroup::query()->create(['name' => 'Gold']);
    $run = importedRun([ClientCsv::row('1', 'asha@example.com', group: 'Gold'), ClientCsv::row('2', 'bina@example.com', status: 'Inactive'), ClientCsv::row('3', 'old@example.com', status: 'Closed')]);
    $gold = ClientGroup::query()->where('name', 'Gold')->sole();

    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->component('admin/migration/invitations')
        // The closed client has no login, and closed clients are never invited.
        ->where('counts', ['total' => 2, 'not_invited' => 2, 'open' => 0, 'expired' => 0, 'queued' => 0])
        ->where('logins.data.0.email', 'asha@example.com')->where('logins.data.0.state', 'not_invited')
        ->missing('logins.data.0.user_id')->missing('logins.data.0.client_id')
        ->where('perHour', 500)->where('blocked', null));
    $this->get(route('admin.migration.imports.invitations', [$run, 'group' => (string) $gold->id]))->assertInertia(fn (Assert $page) => $page->where('counts.total', 1));
    $this->get(route('admin.migration.imports.invitations', [$run, 'status' => 'inactive']))->assertInertia(fn (Assert $page) => $page->where('counts.total', 1)->where('logins.data.0.email', 'bina@example.com'));

    actingAsStaff(['clients.view']);
    $this->get(route('admin.migration.imports.invitations', $run))->assertForbidden();
});

test('sending needs the execute permission and a recent password, and mails the migration template only to the login', function () {
    $run = importedRun([ClientCsv::row('1', 'asha@example.com', first: 'Asha')]);
    EmailTemplate::query()->where('key', ClientLoginInvitations::TEMPLATE_MIGRATION)->update(['cc' => ['cc@example.test'], 'bcc' => ['bcc@example.test']]);

    actingAsStaff(['migration.runs.view']);
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertForbidden();
    actingAsStaff(['migration.runs.view', 'migration.runs.execute']);
    $this->withSession(['auth.password_confirmed_at' => 0])->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasErrors('password_confirmation');
    expect(MigrationInvitation::query()->count())->toBe(0);

    confirmPassword();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '1 invitation is queued.');

    $login = User::query()->where('email', 'asha@example.com')->sole();
    $message = EmailMessage::query()->where('template_key', ClientLoginInvitations::TEMPLATE_MIGRATION)->sole();
    expect(MigrationInvitation::query()->sole()->status)->toBe('sent')
        ->and(invitationTokens('asha@example.com'))->toHaveCount(1)
        ->and($message->subject)->toContain('Set your password')->not->toContain('http')
        ->and($message->has_redactions)->toBeTrue()
        ->and(ClientInvitation::query()->sole()->user_id)->toBe($login->id);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('asha@example.com') && $mail->cc === [] && $mail->bcc === []);

    // A mail that carried the link is never resent from the log; staff send a new link instead.
    actingAsStaff();
    confirmPassword();
    $this->post(route('admin.notifications.emails.resend', $message))->assertStatus(422);
    // A second send finds nobody left to invite: an open invitation is not replaced in bulk.
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasErrors('invitations');
    expect(ClientInvitation::query()->count())->toBe(1);
});

test('accepting an imported login\'s invitation sets its password and verifies its email', function () {
    $run = importedRun([ClientCsv::row('1', 'asha@example.com')]);
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    [$token] = invitationTokens('asha@example.com');
    auth()->logout();

    $this->post(route('platform.client-invitations.accept', $token), ['password' => 'a-New-passw0rd-for-asha', 'password_confirmation' => 'a-New-passw0rd-for-asha'])
        ->assertRedirect(route('client.dashboard'));

    $login = User::query()->where('email', 'asha@example.com')->sole();
    expect($login->email_verified_at)->not->toBeNull()->and(Hash::check('a-New-passw0rd-for-asha', $login->password))->toBeTrue();

    actingAsStaff();
    confirmPassword();
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page->where('counts.total', 0));
});

test('at most invite_per_hour invitations go out in an hour; the rest wait and go out when the hour has room', function () {
    app(Settings::class)->set('migration.invite_per_hour', 2);
    $run = importedRun([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')]);
    Queue::fake();

    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '3 invitations are queued, at most 2 an hour.');
    (new SendInvitations)->handle(app(ClientLoginInvitations::class), app(Settings::class), app(ActivityLogger::class));

    expect(MigrationInvitation::query()->where('status', 'sent')->count())->toBe(2)
        ->and(MigrationInvitation::query()->where('status', 'queued')->count())->toBe(1);
    // The next sender waits until the oldest invitation leaves the hour.
    Queue::assertPushed(SendInvitations::class, fn (SendInvitations $job): bool => $job->delay >= 3600);

    $this->travel(61)->minutes();
    (new SendInvitations)->handle(app(ClientLoginInvitations::class), app(Settings::class), app(ActivityLogger::class));
    expect(MigrationInvitation::query()->where('status', 'sent')->count())->toBe(3);
});

test('a login that signed in, or whose client closed, after it was queued is skipped when the sender reaches it', function () {
    $run = importedRun([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    User::query()->where('email', 'a@example.com')->sole()->forceFill(['last_login_at' => now()])->save();
    Client::query()->where('email', 'b@example.com')->sole()->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    (new SendInvitations)->handle(app(ClientLoginInvitations::class), app(Settings::class), app(ActivityLogger::class));

    expect(MigrationInvitation::query()->pluck('status')->unique()->all())->toBe([InvitationOutcome::Skipped->value])
        ->and(ClientInvitation::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('without an invitation flow the action is disabled with its reason and the contract sends nothing', function () {
    $run = importedRun([ClientCsv::row('1', 'asha@example.com')]);
    $this->app->instance(ClientLoginInvitations::class, new NullClientLoginInvitations);
    $login = User::query()->where('email', 'asha@example.com')->sole();

    expect(app(ClientLoginInvitations::class)->invite($login->clients()->sole()->id, $login->id))->toBe(InvitationOutcome::Unavailable);
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page->where('blocked', 'Invitations are not available: the module that sends them is not installed.'));
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasErrors(['invitations' => 'Invitations are not available: the module that sends them is not installed.']);
    expect(MigrationInvitation::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

test('a login staff linked to an imported client, which has its own password, is never in the list', function () {
    $existing = User::factory()->create(['email' => 'asha@example.com', 'email_verified_at' => now()->subDays(60), 'created_at' => now()->subDays(90)]);
    $run = ClientCsv::check(ClientCsv::upload(ClientCsv::file([ClientCsv::row('1', 'asha@example.com')])));
    $this->put(route('admin.migration.imports.decide', $run), ['row' => 1, 'decision' => 'link'])->assertSessionHasNoErrors();
    $run = ClientCsv::apply($run);

    expect($existing->clients()->count())->toBe(1);
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page->where('counts.total', 0));
});
