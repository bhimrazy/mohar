<?php

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Platform\Notifications\ClientInvitationNotification;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * #443: with the mail server down, adding a client login, sending a new invitation or a password reset no longer
 * ends in an error page. What was committed stays; the invitation is put back as it was; staff get a warning and the
 * activity log has the failure (#438's pattern).
 */

beforeEach(function () {
    $this->client = Client::factory()->create(['company_name' => 'Himal Web']);
    actingAsStaff(['core.clients.view', 'core.users.manage']);
    confirmPassword();
});

/**
 * Framework mail only (no templates), and every notification fails in the transport. Returns the reported messages.
 *
 * @return ArrayObject<int, string>
 */
function clientLoginMailDown(): ArrayObject
{
    app()->instance(SystemMailer::class, new NullSystemMailer);
    app()->instance(AuthMailer::class, new LaravelAuthMailer);
    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new TransportException('smtp down');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new TransportException('smtp down');
        }
    });
    $reported = new ArrayObject;
    app(ExceptionHandler::class)->reportable(function (TransportException $e) use ($reported): void {
        $reported[] = $e->getMessage();
    });

    return $reported;
}

/**
 * A new login invited while mail works, with its open invitation.
 */
function clientLoginInvited(Client $client): User
{
    app()->instance(SystemMailer::class, new NullSystemMailer);
    Notification::fake();
    test()->post(route('admin.platform.clients.users.store', $client), ['email' => 'helper@example.com.np', 'name' => 'Hari Helper', 'permissions' => ['billing']])
        ->assertSessionHasNoErrors();
    Notification::assertSentTo($user = User::query()->where('email', 'helper@example.com.np')->sole(), ClientInvitationNotification::class);

    return $user;
}

test('adding a new login with the mail server down keeps the login, records no invitation and warns', function () {
    $reported = clientLoginMailDown();

    $this->post(route('admin.platform.clients.users.store', $this->client), ['email' => 'helper@example.com.np', 'name' => 'Hari Helper', 'permissions' => ['billing']])
        ->assertRedirect(route('admin.clients.show', $this->client))
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'helper@example.com.np was added, but the invitation could not be sent because the mail server could not be reached. Once mail works, send a password reset from the login\'s menu.');

    $user = User::query()->where('email', 'helper@example.com.np')->sole();
    $failed = ActivityLog::query()->where('event', 'account.notice_failed')->sole();
    expect($this->client->users()->whereKey($user->id)->exists())->toBeTrue()
        ->and(ClientInvitation::query()->count())->toBe(0)
        ->and($reported->getArrayCopy())->toBe(['smtp down'])
        ->and($failed->client_id)->toBe($this->client->id)
        ->and($failed->properties)->toMatchArray(['notice' => 'auth.user_invitation', 'recipient' => 'helper@example.com.np', 'reason' => TransportException::class]);
});

test('a new invitation with the mail server down leaves the earlier link working and warns', function () {
    $user = clientLoginInvited($this->client);
    $before = ClientInvitation::query()->sole()->only(['token_hash', 'sent_at', 'expires_at', 'password_fingerprint']);
    $this->travel(2)->minutes();
    clientLoginMailDown();

    $this->post(route('admin.platform.clients.users.invitation', [$this->client, $user]))
        ->assertRedirect(route('admin.clients.show', $this->client))
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'The new invitation to helper@example.com.np could not be sent because the mail server could not be reached. The earlier link is unchanged. Try again once mail works.');

    expect(ClientInvitation::query()->sole()->only(array_keys($before)))->toEqual($before)
        ->and(ActivityLog::query()->where('event', 'client.invitation_resent')->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'account.notice_failed')->sole()->properties)->toMatchArray(['notice' => 'auth.user_invitation']);
});

test('a staff password reset with the mail server down is refused with a message, not an error page', function () {
    $user = User::factory()->create(['email' => 'legacy@example.com.np']);
    $this->client->users()->attach($user, ['is_owner' => false, 'permissions' => '[]']);
    $reported = clientLoginMailDown();

    $this->post(route('admin.platform.clients.users.password-reset', [$this->client, $user]))
        ->assertRedirect()
        ->assertSessionHasErrors(['user' => 'The password reset email could not be sent because the mail server could not be reached. Try again once mail works.']);

    expect($reported->getArrayCopy())->toBe(['smtp down'])
        ->and(ActivityLog::query()->where('event', 'client.password_reset_sent')->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'account.notice_failed')->sole()->properties)->toMatchArray(['notice' => 'auth.password_reset', 'recipient' => 'legacy@example.com.np']);
});

test('a staff email change whose copy to the new address alone fails does not blame the previous address', function () {
    actingAsStaff();
    confirmPassword();
    $other = User::factory()->staff()->create(['email' => 'agent@example.com.np']);
    Notification::fake();
    app()->instance(SystemMailer::class, new class implements SystemMailer
    {
        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return $recipient === 'agent.new@example.com.np' ? SystemMailOutcome::Refused : SystemMailOutcome::Taken;
        }
    });

    $this->put(route('admin.platform.staff.update', $other), ['name' => $other->name, 'email' => 'agent.new@example.com.np', 'role_id' => $other->role_id, 'is_active' => true])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Staff member '.$other->name.' saved. A security notice about it could not be delivered. The activity log has the details.');

    expect(ActivityLog::query()->where('event', 'account.notice_failed')->sole()->properties)->toMatchArray(['recipient' => 'agent.new@example.com.np']);
});
