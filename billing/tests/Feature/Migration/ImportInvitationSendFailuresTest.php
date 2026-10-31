<?php

use App\Contracts\Auth\ClientLoginInvitations;
use App\Contracts\Notifications\SystemMailer;
use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Re-review probes for PR #255 at 7f931a97. Probes R1 and R5 fail at that head (new findings); R2 to R4 pass and record
 * what the re-review verified about the fix round.
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
function pr255rRun(array $rows): MigrationRun
{
    return ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file($rows))));
}

function pr255rSend(): void
{
    (new SendInvitations)->handle(app(ClientLoginInvitations::class), app(Settings::class), app(ActivityLogger::class));
}

test('probe R1: with "Stop sending email" on, nothing is mailed but every invitation is recorded sent and shown open', function () {
    $run = pr255rRun([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    app(Settings::class)->set('notifications.mail_disabled', true);
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255rSend();

    Mail::assertNothingSent();
    expect(EmailMessage::query()->sole()->suppressed_reason)->toBe('mail_disabled');
    // Expected: not sent, the login stays invitable (or the sender holds, like the link-host problem()).
    expect(MigrationInvitation::query()->sole()->status)->not->toBe(InvitationOutcome::Sent->value, 'a suppressed mail is recorded as sent');
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->where('counts.open', 0));
});

test('probe R2 (passes): a refused re-send restores the earlier link instead of leaving a fresh one', function () {
    $run = pr255rRun([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255rSend();
    // The link expired (no clock travel: queue() stamps rows with the database clock, see probe R5).
    ClientInvitation::query()->update(['sent_at' => now()->subDays(8), 'expires_at' => now()->subDay()]);
    MigrationInvitation::query()->update(['sent_at' => now()->subDays(8)]);
    $before = ClientInvitation::query()->sole()->only(['token_hash', 'sent_at', 'expires_at']);

    $this->app->instance(SystemMailer::class, new class implements SystemMailer
    {
        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return SystemMailOutcome::Refused;
        }
    });
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255rSend();

    expect(MigrationInvitation::query()->orderBy('id')->pluck('status')->all())->toBe(['sent', 'refused'])
        ->and(ClientInvitation::query()->sole()->only(['token_hash', 'sent_at', 'expires_at']))->toEqual($before);
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->where('logins.data.0.state', 'expired'));
});

test('probe R3 (passes): a send that throws fails only its row, the rest stay queued and go out on the retry', function () {
    $run = pr255rRun([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    $victim = User::query()->where('email', 'b@example.com')->value('id');
    $real = app(ClientLoginInvitations::class);
    $this->app->instance(ClientLoginInvitations::class, new class($real, $victim) implements ClientLoginInvitations
    {
        public function __construct(private ClientLoginInvitations $real, private int $victim) {}

        public function problem(): ?string
        {
            return $this->real->problem();
        }

        public function invite(int $clientId, int $userId, InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome
        {
            if ($userId === $this->victim) {
                throw new RuntimeException('deadlock');
            }

            return $this->real->invite($clientId, $userId, $purpose, $actorId);
        }
    });

    expect(fn () => pr255rSend())->toThrow(RuntimeException::class);
    expect(MigrationInvitation::query()->orderBy('id')->pluck('status')->all())->toBe(['sent', 'failed', 'queued'])
        ->and(MigrationInvitation::query()->where('status', 'failed')->value('reason'))->not->toBeNull();

    pr255rSend(); // the queue's retry
    expect(MigrationInvitation::query()->orderBy('id')->pluck('status')->all())->toBe(['sent', 'failed', 'sent'])
        ->and(ClientInvitation::query()->where('user_id', $victim)->exists())->toBeFalse();
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->where('counts.not_invited', 1));
});

test('probe R4 (passes): a row a live sender is sending is neither swept to failed nor invited twice', function () {
    $run = pr255rRun([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    $row = MigrationInvitation::query()->sole();
    $row->forceFill(['status' => MigrationInvitation::SENDING, 'updated_at' => now()->subSeconds(MigrationInvitation::STALE_SECONDS - 10)])->save();
    MigrationInvitation::query()->insert(['migration_run_id' => $row->migration_run_id, 'client_id' => $row->client_id, 'user_id' => $row->user_id, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

    pr255rSend();

    expect(MigrationInvitation::query()->orderBy('id')->pluck('status')->all())->toBe(['sending', 'skipped'])
        ->and(EmailMessage::query()->count())->toBe(0);
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->where('logins.data.0.state', 'queued'));
});

test('probe R5: queued rows are stamped with the database session clock, displaced when that zone is not UTC', function () {
    // A PostgreSQL server whose TimeZone is the host's zone (the default from initdb), e.g. a server in Nepal.
    if (DB::getDriverName() === 'pgsql') {
        DB::statement("set time zone 'Asia/Kathmandu'");
    }
    $run = pr255rRun([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    $this->freezeSecond();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();

    // Every other timestamp in the app is written from now() in UTC; this one comes from the database.
    $row = MigrationInvitation::query()->sole();
    expect(abs($row->created_at->diffInSeconds(now())))->toBeLessThan(5, 'created_at is '.$row->created_at.' while now() is '.now());
});
