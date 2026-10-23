<?php

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Core\Support\NullSystemMailer;
use Illuminate\Contracts\Notifications\Dispatcher;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Review probes for PR #438: a security notice that could not be delivered must leave a trace staff can see, not only
 * an error-log entry; the warning toast goes to the actor, who in a takeover is the attacker.
 */
function pr438FailingMail(Throwable $error): void
{
    app()->instance(SystemMailer::class, new NullSystemMailer);
    app()->instance(AuthMailer::class, new LaravelAuthMailer);
    app()->instance(Dispatcher::class, new class($error) implements Dispatcher
    {
        public function __construct(private Throwable $error) {}

        public function send($notifiables, $notification): void
        {
            throw $this->error;
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw $this->error;
        }
    });
}

test('probe: an undelivered old-address notice after a staff email change is in the activity log', function () {
    actingAsStaff();
    confirmPassword();
    $other = User::factory()->staff()->create(['email' => 'agent@example.com.np']);
    pr438FailingMail(new TransportException('smtp down'));

    $this->put(route('admin.platform.staff.update', $other), ['name' => $other->name, 'email' => 'agent.new@example.com.np', 'role_id' => $other->role_id, 'is_active' => true])
        ->assertRedirect(route('admin.platform.staff.index'));

    // The self-service path records auth.email_change_notice_failed; the staff path should record its equivalent.
    $logged = ActivityLog::query()->get()->contains(fn (ActivityLog $entry): bool => str_contains((string) $entry->event, 'notice_failed') || str_contains((string) $entry->event, 'mail_failed'));
    expect($logged)->toBeTrue();
});

test('probe: an undelivered staff two-factor reset notice is in the activity log', function () {
    actingAsStaff();
    confirmPassword();
    $other = User::factory()->staff()->create(['email' => 'agent@example.com.np']);
    pr438FailingMail(new TransportException('smtp down'));

    $this->post(route('admin.platform.staff.reset-two-factor', $other))->assertRedirect();

    $logged = ActivityLog::query()->get()->contains(fn (ActivityLog $entry): bool => str_contains((string) $entry->event, 'notice_failed') || str_contains((string) $entry->event, 'mail_failed'));
    expect($logged)->toBeTrue();
});

test('probe: a programming error in the notification surfaces instead of becoming a mail warning', function () {
    actingAsStaff();
    confirmPassword();
    $other = User::factory()->staff()->create(['email' => 'agent@example.com.np']);
    pr438FailingMail(new TypeError('a bug, not a mail server'));

    $this->put(route('admin.platform.staff.update', $other), ['name' => $other->name, 'email' => 'agent.new@example.com.np', 'role_id' => $other->role_id, 'is_active' => true])
        ->assertServerError();
});

test('probe: the self-service path records the undelivered old-address notice', function () {
    $user = User::factory()->create(['email' => 'asha@example.com.np']);
    $this->actingAs($user);
    confirmPassword();
    pr438FailingMail(new TransportException('smtp down'));

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])->assertRedirect(route('profile.edit'));

    expect(ActivityLog::query()->where('event', 'auth.email_change_notice_failed')->exists())->toBeTrue();
});
