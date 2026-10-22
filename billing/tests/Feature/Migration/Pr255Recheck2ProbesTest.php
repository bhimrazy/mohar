<?php

use App\Contracts\Auth\ClientLoginInvitations;
use App\Models\User;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Migration\Models\MigrationInvitation;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Migration\ClientCsv;

/**
 * Second re-review probes for PR #255 at 851c1c26 (the Held outcome for R1). All pass at that head: they record that a
 * held invitation cannot loop, leaves no usable link, does not use up the hourly room, and resumes once mail is on.
 * Probe H4 documents the window the fix report names as not verified (mail switched off after the mail job is queued).
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
function pr255r2Run(array $rows): MigrationRun
{
    return ClientCsv::apply(ClientCsv::check(ClientCsv::upload(ClientCsv::file($rows))));
}

function pr255r2Send(): void
{
    (new SendInvitations)->handle(app(ClientLoginInvitations::class), app(Settings::class), app(ActivityLogger::class));
}

test('probe H1 (passes): while mail stays off, repeated senders and "continue" clicks never loop, never issue a link and dispatch no next job', function () {
    $run = pr255r2Run([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')]);
    app(Settings::class)->set('notifications.mail_disabled', true);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();

    foreach (range(1, 2) as $attempt) {
        Queue::fake();
        pr255r2Send();
        // The sender stops on the first held row and schedules nothing: no self-re-queueing chain.
        Queue::assertNothingPushed();

        // Staff click "Continue sending": the resume branch restarts exactly one sender, nothing new is queued.
        $this->post(route('admin.migration.imports.invitations.send', $run))->assertRedirect()->assertSessionHasNoErrors();
        $this->travel(61)->seconds(); // past the 6/min send throttle
        expect(MigrationInvitation::query()->count())->toBe(3);
        Queue::assertPushed(SendInvitations::class, 1);
    }

    pr255r2Send();

    // One suppressed message per sender run (3), all for the first login; the other two were never attempted.
    expect(MigrationInvitation::query()->count())->toBe(3)
        ->and(MigrationInvitation::query()->pluck('status')->unique()->all())->toBe([MigrationInvitation::QUEUED])
        ->and(MigrationInvitation::query()->whereNotNull('sent_at')->count())->toBe(0)
        ->and(ClientInvitation::query()->count())->toBe(0)
        ->and(EmailMessage::query()->count())->toBe(3)
        ->and(EmailMessage::query()->pluck('to')->map(fn ($to) => is_array($to) ? implode(',', $to) : $to)->unique()->values()->all())->toHaveCount(1);
    Mail::assertNothingSent();
});

test('probe H2 (passes): a held attempt leaves no usable token: the log copy is redacted, cannot be resent, and no invitation row holds its hash', function () {
    $run = pr255r2Run([ClientCsv::row('1', 'a@example.com')]);
    app(Settings::class)->set('notifications.mail_disabled', true);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255r2Send();

    $message = EmailMessage::query()->sole();
    expect($message->suppressed_reason)->toBe('mail_disabled')
        ->and($message->has_redactions)->toBeTrue()
        ->and((string) $message->body_text.(string) $message->body_html)->not->toMatch('#account-invitation/[A-Za-z0-9]{20,}#')->not->toMatch('#[A-Za-z0-9]{48}#')
        ->and($message->cc)->toBeEmpty()->and($message->bcc)->toBeEmpty()
        ->and(ClientInvitation::query()->count())->toBe(0);

    // Mail back on: the suppressed copy cannot be pushed out from the email log.
    app(Settings::class)->set('notifications.mail_disabled', false);
    $this->post(route('admin.notifications.emails.resend', $message))->assertStatus(422);
    Mail::assertNothingSent();
});

test('probe H3 (passes): held attempts do not use up the hourly room, and resuming sends with fresh links that work', function () {
    app(Settings::class)->set('migration.invite_per_hour', 2);
    $run = pr255r2Run([ClientCsv::row('1', 'a@example.com'), ClientCsv::row('2', 'b@example.com'), ClientCsv::row('3', 'c@example.com')]);
    app(Settings::class)->set('notifications.mail_disabled', true);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255r2Send();
    pr255r2Send();

    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page
        ->where('counts.queued', 3)->where('counts.open', 0)->where('held', fn (?string $reason): bool => is_string($reason) && str_contains($reason, 'Stop sending email')));

    app(Settings::class)->set('notifications.mail_disabled', false);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    pr255r2Send();
    Queue::pushed(SendEmailMessage::class)->each(fn (SendEmailMessage $job) => $job->handle(app(Settings::class)));

    // The hour still has its full room of 2: the held attempts were not counted.
    expect(MigrationInvitation::query()->where('status', MigrationInvitation::SENT)->count())->toBe(2)
        ->and(MigrationInvitation::query()->where('status', MigrationInvitation::QUEUED)->count())->toBe(1)
        ->and(ClientInvitation::query()->count())->toBe(2);
    Mail::assertSent(TemplatedMail::class, 2);

    // The resumed link is a working one: the accept page opens for it.
    $sent = Mail::sent(TemplatedMail::class)->first();
    preg_match('#account-invitation/([A-Za-z0-9]{48})#', $sent->render(), $match);
    expect($match)->toHaveKey(1);
    auth()->logout();
    $this->get(route('platform.client-invitations.show', $match[1]))->assertOk();

    // While the next hour is awaited, the held banner is gone (the remaining row is plain queued).
    actingAsStaff();
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page->where('held', null)->where('counts.open', 2));
});

test('probe H4 (passes, documents the residual window): mail switched off after the mail job is queued leaves an open invitation that was never mailed', function () {
    $run = pr255r2Run([ClientCsv::row('1', 'a@example.com')]);
    Queue::fake();
    $this->post(route('admin.migration.imports.invitations.send', $run))->assertSessionHasNoErrors();
    Queue::fake();
    pr255r2Send();

    // The mail job waits on the notifications queue; staff switch mail off before a worker picks it up.
    app(Settings::class)->set('notifications.mail_disabled', true);
    Queue::pushed(SendEmailMessage::class)->each(fn (SendEmailMessage $job) => $job->handle(app(Settings::class)));

    Mail::assertNothingSent();
    expect(EmailMessage::query()->sole()->suppressed_reason)->toBe('mail_disabled')
        ->and(MigrationInvitation::query()->sole()->status)->toBe(MigrationInvitation::SENT)
        ->and(ClientInvitation::query()->count())->toBe(1);
    // Shown as an open invitation; bulk sending skips it until it expires (staff can resend from the client's logins).
    $this->get(route('admin.migration.imports.invitations', $run))->assertInertia(fn (Assert $page) => $page->where('counts.open', 1));
    expect(User::query()->where('email', 'a@example.com')->exists())->toBeTrue();
});
