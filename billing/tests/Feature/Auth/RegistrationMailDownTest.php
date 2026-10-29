<?php

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Core\Support\NullSystemMailer;
use Illuminate\Contracts\Notifications\Dispatcher;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * #520: a sign-up whose verification email hits a down mail server (no template system) signs the person in and says
 * so, instead of leaving a committed login nobody signed into and an error page.
 */

test('a sign-up with the mail server down signs the person in, warns, and logs the failure', function () {
    Currency::factory()->create();
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

    $this->post(route('register'), ['name' => 'Asha Rai', 'email' => 'asha@example.com.np', 'password' => 'Chosen-correct-horse-1', 'password_confirmation' => 'Chosen-correct-horse-1'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'warning');

    $user = User::query()->where('email', 'asha@example.com.np')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->last_login_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'auth.email_verification_failed')->sole()->properties)->toMatchArray(['reason' => TransportException::class]);
});
