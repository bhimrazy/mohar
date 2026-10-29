<?php

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Platform\Notifications\ClientInvitationNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Third re-review of #520. A first invitation (no invitation row) now replaces the password before mailing. When
 * that mail also fails, the replacement is committed: the login is no longer "never used" (password_changed_at is
 * after created_at), so it can never be invited again, only sent a password reset (#443 regression).
 */

function rr3MailDown(): void
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
}

test('R5: a login whose first invitation and first resend both hit a down mail server can still be invited once mail works', function () {
    $client = Client::factory()->withOwner()->create(['company_name' => 'Himal Web']);
    actingAsStaff(['core.clients.view', 'core.users.manage']);
    confirmPassword();

    rr3MailDown();
    $this->post(route('admin.platform.clients.users.store', $client), ['email' => 'helper@example.com.np', 'name' => 'Hari Helper', 'permissions' => ['billing']]);
    $user = User::query()->where('email', 'helper@example.com.np')->sole();

    // Mail still down for the first resend.
    $this->travel(1)->minutes();
    app()->forgetScopedInstances();
    $this->post(route('admin.platform.clients.users.invitation', [$client, $user]))->assertInertiaFlash('toast.type', 'warning');

    // Mail works again.
    $this->travel(1)->minutes();
    app()->instance(Dispatcher::class, Notification::fake());
    app()->forgetScopedInstances();
    $login = collect($this->get(route('admin.clients.show', $client))->viewData('page')['props']['users'])->firstWhere('id', $user->id);
    expect($login['can_invite'])->toBeTrue();

    $this->post(route('admin.platform.clients.users.invitation', [$client, $user]))->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ClientInvitationNotification::class);
    expect(ClientInvitation::query()->where('user_id', $user->id)->exists())->toBeTrue();
});
