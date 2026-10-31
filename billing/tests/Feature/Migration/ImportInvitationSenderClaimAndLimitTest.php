<?php

use App\Contracts\Auth\ClientLoginInvitations;
use App\Contracts\Notifications\SystemMailer;
use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

require_once dirname(__DIR__).'/Billing/Support/race.php';

/**
 * Review board probes for PR #255 (client importer B2a, invitations). Probes 1 and 2 fail at 67613322; the others
 * pass and record what was verified.
 */
beforeEach(function () {
    Storage::fake('objects');
    Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
    app(TemplateCatalog::class)->install('en');
    Mail::fake();
    actingAsStaff();
    confirmPassword();
});

/** @param  list<list<string>>  $rows */
function pr255Run(array $rows): MigrationRun
{
    return ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file($rows))));
}

function pr255Send(): void
{
    (new SendInvitations)->handle(app(ClientLoginInvitations::class), app(Settings::class), app(ActivityLogger::class));
}

test('probe 1: a sender that dies mid-batch leaves its claimed row in sending forever; the login is never invited', function () {
    $run = pr255Run([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();

    // The first invite() dies (mail transport, a deadlock, the worker killed at its timeout): the row is already claimed.
    $this->app->instance(ClientLoginInvitations::class, new class implements ClientLoginInvitations
    {
        public function problem(): ?string
        {
            return null;
        }

        public function invite(int $clientId, int $userId, InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome
        {
            throw new RuntimeException('worker died');
        }
    });
    expect(fn () => pr255Send())->toThrow(RuntimeException::class);
    $this->app->forgetInstance(ClientLoginInvitations::class);

    // The retry and every later sender only read queued rows.
    pr255Send();
    $this->post(route('admin.migration.imports.invitations.send', $run));
    pr255Send();

    expect(MigrationInvitation::query()->where('status', MigrationInvitation::SENDING)->count())->toBe(0, 'a row stays in sending with no way out')
        ->and(ClientInvitation::query()->count())->toBe(2, 'the login whose row was claimed is never invited');
});

test('probe 2: an invitation the mailer refused is shown as sent and open, so bulk sending skips it until it expires', function () {
    $run = pr255Run([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    $this->app->instance(SystemMailer::class, new class implements SystemMailer
    {
        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return SystemMailOutcome::Refused;
        }
    });
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255Send();

    expect(MigrationInvitation::query()->sole()->status)->toBe(InvitationOutcome::Refused->value);
    Mail::assertNothingSent();
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->where('logins.data.0.state', 'not_invited')
        ->where('counts.open', 0));
});

test('probe 3 (passes): the same login queued twice is invited once', function () {
    $run = pr255Run([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    $row = MigrationInvitation::query()->sole();
    MigrationInvitation::query()->insert(['migration_run_id' => $row->migration_run_id, 'client_id' => $row->client_id, 'user_id' => $row->user_id, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

    pr255Send();

    expect(MigrationInvitation::query()->orderBy('id')->pluck('status')->all())->toBe(['sent', 'skipped'])
        ->and(EmailMessage::query()->count())->toBe(1);
});

test('probe 4 (passes): the hourly window holds to the second at its boundary', function () {
    app(Settings::class)->set('migration.invite_per_hour', 2);
    $run = pr255Run([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')]);
    Queue::fake();
    $this->freezeSecond();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255Send();
    expect(MigrationInvitation::query()->where('status', 'sent')->count())->toBe(2);

    $this->travel(3599)->seconds();
    pr255Send();
    expect(MigrationInvitation::query()->where('status', 'sent')->count())->toBe(2);

    $this->travel(1)->seconds();
    pr255Send();
    expect(MigrationInvitation::query()->where('status', 'sent')->count())->toBe(3);
});

test('probe 5 (passes): a link mailed to a login that then signs in, resets its password or changes email cannot be accepted', function (string $change) {
    $run = pr255Run([ClientCsv::row('1', 'a@example.com')]);
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    $token = null;
    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use (&$token): bool {
        preg_match('#/account-invitation/([A-Za-z0-9]+)#', $mail->plainText, $m);
        $token = $m[1] ?? $token;

        return true;
    });
    $login = User::query()->where('email', 'a@example.com')->sole();
    $this->travel(1)->minutes();
    match ($change) {
        'sign-in' => $login->forceFill(['last_login_at' => now()])->save(),
        'reset' => $login->forceFill(['password' => 'Reset-Passw0rd-by-owner'])->save(),
        'email' => $login->forceFill(['email' => 'attacker@example.com'])->save(),
    };
    auth()->logout();

    // A sign-in voids the link; a password or email change deletes it (404).
    $this->post(route('platform.client-invitations.accept', $token), ['password' => 'Attacker-Passw0rd-1', 'password_confirmation' => 'Attacker-Passw0rd-1'])
        ->assertStatus($change === 'sign-in' ? 302 : 404);
    $this->assertGuest();
    expect(Hash::check('Attacker-Passw0rd-1', $login->fresh()->password))->toBeFalse()
        ->and(ClientInvitation::query()->whereNotNull('accepted_at')->exists())->toBeFalse();
})->with(['sign-in', 'reset', 'email']);

test('probe 6 (passes, PostgreSQL): two senders at once never pass the hourly limit or invite a login twice', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pgsql only');
    }

    $this->useSeededDatabase('pr255_senders', function (): void {
        Currency::factory()->create(['code' => 'NPR', 'is_default' => true]);
        app(TemplateCatalog::class)->install('en');
        app(Settings::class)->set('migration.invite_per_hour', 5);
        $source = DB::table('migration_sources')->insertGetId(['source_system' => 'csv', 'name' => 'Probe', 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('migration_runs')->insertGetId(['migration_source_id' => $source, 'source_system' => 'csv', 'entity' => 'client', 'status' => 'completed', 'file_name' => 'p.csv', 'file_sha256' => str_repeat('a', 64), 'file_bytes' => 1, 'delimiter' => ',', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        foreach (range(1, 10) as $i) {
            $client = Client::factory()->create(['email' => "race{$i}@example.test", 'status' => 'active', 'currency' => 'NPR']);
            $user = User::factory()->create(['email' => "race{$i}@example.test", 'email_verified_at' => null]);
            $client->users()->attach($user, ['is_owner' => true]);
            MigrationInvitation::query()->create(['migration_run_id' => $run, 'client_id' => $client->id, 'user_id' => $user->id, 'status' => 'queued']);
        }
    });
    $database = DB::connection()->getDatabaseName();

    $results = runRaceWorkers([
        'first' => ['invite', $database, '0', '0', '0', '300'],
        'second' => ['invite', $database, '0', '0', '100', '300'],
    ], 60.0);

    $sent = MigrationInvitation::query()->where('status', 'sent')->count();
    $perUser = ClientInvitation::query()->select('user_id', DB::raw('count(*) as n'))->groupBy('user_id')->pluck('n')->max();
    expect($results)->toHaveKeys(['first', 'second'])
        ->and($sent)->toBe(5)
        ->and(MigrationInvitation::query()->where('status', 'queued')->count())->toBe(5)
        ->and(MigrationInvitation::query()->where('status', 'sending')->count())->toBe(0)
        ->and((int) $perUser)->toBe(1)
        ->and(EmailMessage::query()->count())->toBe(5);
});
