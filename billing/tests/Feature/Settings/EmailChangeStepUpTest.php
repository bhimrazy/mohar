<?php

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Notifications\LoginEmailNotice;
use App\Modules\Core\Support\LoginEmailChange;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * #388: a session alone can't change the sign-in email. It needs a recent password confirmation; a login without a
 * password must set one first. The old address hears about every change, and the new one must be verified.
 */
final class RecordingEmailChangeMailer implements SystemMailer
{
    /** @var list<array{key: string, to: string, context: array<string, mixed>}> */
    public array $sent = [];

    public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
    {
        $this->sent[] = ['key' => $templateKey, 'to' => $recipient instanceof User ? $recipient->email : $recipient, 'context' => $context];

        return SystemMailOutcome::Taken;
    }
}

beforeEach(function () {
    Notification::fake();
    $this->mailer = new RecordingEmailChangeMailer;
    app()->instance(SystemMailer::class, $this->mailer);
});

function passwordlessLogin(string $email = 'asha@example.com.np'): User
{
    $user = User::factory()->create(['email' => $email, 'name' => 'Asha Gurung']);
    // No password: '' on develop's NOT NULL column, null once #384 makes it nullable; both count as none.
    DB::table('users')->where('id', $user->id)->update(['password' => '']);

    return $user->fresh();
}

test('a new email is refused without a recent password, over Inertia and JSON', function () {
    $user = User::factory()->create(['email' => 'asha@example.com.np', 'name' => 'Asha']);
    $this->actingAs($user);

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'attacker@evil.test'])
        ->assertSessionHasErrors('password_confirmation');
    $this->patchJson(route('profile.update'), ['name' => 'Asha', 'email' => 'attacker@evil.test'])
        ->assertStatus(423);

    expect($user->fresh()->email)->toBe('asha@example.com.np')
        ->and($this->mailer->sent)->toBe([]);
});

test('a name-only change needs no step-up', function () {
    $user = User::factory()->create(['email' => 'asha@example.com.np']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Asha Gurung', 'email' => 'ASHA@example.com.np '])
        ->assertSessionHasNoErrors();

    expect($user->fresh())->name->toBe('Asha Gurung')->email_verified_at->not->toBeNull();
});

test('after step-up the email changes, the new address is verified again and the old one gets a masked notice', function () {
    $user = User::factory()->create(['email' => 'asha@example.com.np', 'name' => 'Asha Gurung']);
    $this->actingAs($user);
    confirmPassword();

    $this->patch(route('profile.update'), ['name' => 'Asha Gurung', 'email' => 'asha.new@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->email->toBe('asha.new@example.com')->email_verified_at->toBeNull();
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);

    expect($this->mailer->sent)->toHaveCount(1)
        ->and($this->mailer->sent[0])->toMatchArray(['key' => 'auth.email_changed', 'to' => 'asha@example.com.np'])
        ->and($this->mailer->sent[0]['context']['notice'])->toMatchArray(['old_email' => 'asha@example.com.np', 'new_email' => 'a***w@example.com']);
});

test('a staff member\'s own change uses the staff notice', function () {
    $staff = actingAsStaff();
    confirmPassword();
    $old = $staff->email;

    $this->patch(route('profile.update'), ['name' => $staff->name, 'email' => 'sita.new@example.com.np'])->assertSessionHasNoErrors();

    expect($this->mailer->sent[0])->toMatchArray(['key' => 'staff.email_changed', 'to' => $old])
        ->and($this->mailer->sent[0]['context']['notice']['new_email'])->toBe('s***w@example.com.np');
});

test('a login without a password must set one before changing its email, whatever the session holds', function () {
    $user = passwordlessLogin();
    $this->actingAs($user);
    // A stale or forged step-up timestamp does not stand in for a password the login does not have.
    confirmPassword();

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('auth.user.has_password', false)->where('setPasswordUrl', route('password.set-link')));
    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'attacker@evil.test'])
        ->assertSessionHasErrors(['email' => 'Set a password first. Your account has no password, and changing the email needs one.'])
        ->assertSessionDoesntHaveErrors('password_confirmation');
    $this->patchJson(route('profile.update'), ['name' => 'Asha', 'email' => 'attacker@evil.test'])
        ->assertStatus(423)->assertJsonPath('code', 'password_not_set');
    // The name alone still saves.
    $this->patch(route('profile.update'), ['name' => 'Asha G', 'email' => 'asha@example.com.np'])->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBe('asha@example.com.np')->name->toBe('Asha G')
        ->and($this->mailer->sent)->toBe([]);
});

test('a hijacked session cannot move the account without the password', function () {
    $user = User::factory()->create(['email' => 'ram@example.com.np']);
    $this->actingAs($user);

    $this->patch(route('profile.update'), ['name' => 'Ram', 'email' => 'attacker@evil.test'])->assertSessionHasErrors('password_confirmation');
    $this->post(route('password.confirm.store'), ['password' => 'wrong-password'])->assertSessionHasErrors('password');
    $this->patch(route('profile.update'), ['name' => 'Ram', 'email' => 'attacker@evil.test'])->assertSessionHasErrors('password_confirmation');

    // A confirmation older than the step-up window no longer counts.
    $this->withSession(['auth.password_confirmed_at' => time() - (int) config('auth.step_up_seconds') - 1]);
    $this->patch(route('profile.update'), ['name' => 'Ram', 'email' => 'attacker@evil.test'])->assertSessionHasErrors('password_confirmation');

    expect($user->fresh()->email)->toBe('ram@example.com.np');
});

test('without a template system the old address gets the fallback notice', function () {
    app()->instance(SystemMailer::class, new class implements SystemMailer
    {
        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return SystemMailOutcome::Unavailable;
        }
    });
    $user = User::factory()->create(['email' => 'asha@example.com.np']);
    $this->actingAs($user);
    confirmPassword();

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(LoginEmailNotice::class, fn (LoginEmailNotice $notice, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'asha@example.com.np'
        && str_contains(implode(' ', $notice->lines), 'a***w@example.com') && ! str_contains(implode(' ', $notice->lines), 'asha.new@'));
});

test('the notice template renders to the old address with the new one masked', function () {
    app()->forgetInstance(SystemMailer::class);
    app(TemplateCatalog::class)->install('en');
    $user = User::factory()->create(['email' => 'asha@example.com.np']);
    $this->actingAs($user);
    confirmPassword();

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])->assertSessionHasNoErrors();

    $notice = EmailMessage::query()->where('template_key', 'auth.email_changed')->sole();
    expect($notice->status)->not->toBe(EmailStatus::Failed)
        ->and($notice->to)->toBe(['asha@example.com.np'])
        ->and($notice->body_text)->toContain('a***w@example.com')->not->toContain('asha.new@example.com');
});

test('masking keeps the last character only for local parts of four or more characters', function () {
    expect(LoginEmailChange::mask('a@x.np'))->toBe('a***@x.np')
        ->and(LoginEmailChange::mask('ab@x.np'))->toBe('a***@x.np')
        ->and(LoginEmailChange::mask('abc@x.np'))->toBe('a***@x.np')
        ->and(LoginEmailChange::mask('abcd@x.np'))->toBe('a***d@x.np')
        ->and(LoginEmailChange::mask('राम्रो@उदाहरण.नेपाल'))->toBe('र***ो@उदाहरण.नेपाल')
        ->and(LoginEmailChange::mask('noat'))->toBe('n***t');
});

test('the change is logged with both addresses, and the verification mail goes to the new one', function () {
    $user = User::factory()->create(['email' => 'asha@example.com.np']);
    $this->actingAs($user);
    confirmPassword();

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])->assertSessionHasNoErrors();

    $entry = ActivityLog::query()->where('event', 'auth.email_changed')->sole();
    expect($entry->properties)->toMatchArray(['old_email' => 'asha@example.com.np', 'new_email' => 'asha.new@example.com']);
});

test('a notice the template system refuses or suppresses warns the person and is logged, with no other mail', function (SystemMailOutcome $outcome) {
    app()->instance(SystemMailer::class, new class($outcome) implements SystemMailer
    {
        public function __construct(private SystemMailOutcome $outcome) {}

        public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
        {
            return $this->outcome;
        }
    });
    $user = User::factory()->create(['email' => 'asha@example.com.np']);
    $this->actingAs($user);
    confirmPassword();

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect($user->fresh()->email)->toBe('asha.new@example.com')
        ->and(ActivityLog::query()->where('event', 'auth.email_change_notice_failed')->sole()->properties)->toMatchArray(['outcome' => $outcome->value]);
    Notification::assertSentTimes(LoginEmailNotice::class, 0);
})->with([SystemMailOutcome::Refused, SystemMailOutcome::Suppressed]);

test('a failing fallback mail does not turn a committed change into an error', function () {
    app()->instance(SystemMailer::class, new NullSystemMailer);
    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            if ($notification instanceof LoginEmailNotice) {
                throw new RuntimeException('smtp down');
            }
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            $this->send($notifiables, $notification);
        }
    });
    $user = User::factory()->create(['email' => 'asha@example.com.np']);
    $this->actingAs($user);
    confirmPassword();

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect($user->fresh()->email)->toBe('asha.new@example.com')
        ->and(ActivityLog::query()->where('event', 'auth.email_change_notice_failed')->count())->toBe(1);
});

test('two logins taking the same address at once give a validation error, not a 500', function () {
    $a = User::factory()->create(['email' => 'a@example.com']);
    $b = User::factory()->create(['email' => 'b@example.com']);
    // B commits the address between A's check and A's write.
    User::saving(function (User $user) use ($a, $b): void {
        if ($user->id === $a->id && $user->isDirty('email')) {
            DB::table('users')->where('id', $b->id)->update(['email' => 'z@example.com']);
        }
    });

    expect(fn () => app(LoginEmailChange::class)->change($a, 'z@example.com'))->toThrow(ValidationException::class);
    expect($a->fresh()->email)->toBe('a@example.com');
});

test('a staff member changing their own email loses verification on both paths', function () {
    $staff = actingAsStaff();
    confirmPassword();

    $this->put(route('admin.platform.staff.update', $staff), ['name' => $staff->name, 'email' => 'self.new@example.com.np', 'role_id' => $staff->role_id, 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect($staff->fresh())->email->toBe('self.new@example.com.np')->email_verified_at->toBeNull();
    Notification::assertSentTo($staff->fresh(), VerifyEmail::class);
    $this->actingAs($staff->fresh())->get(route('admin.dashboard'))->assertRedirect();
});

test('editing a client\'s billing contact never changes a login\'s email', function () {
    Currency::factory()->create();
    $staff = actingAsStaff();
    [$user, $client] = [User::factory()->create(['email' => 'owner@example.com.np']), Client::factory()->create()];
    $client->users()->attach($user);

    $this->put(route('admin.clients.update', $client), [...$client->only(['first_name', 'last_name', 'currency']), 'email' => 'billing.new@example.com.np', 'status' => 'active'])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->put(route('client.account.update'), [
        'first_name' => 'New', 'last_name' => 'Name', 'email' => 'billing.newer@example.com.np', 'address_1' => '1 Main St', 'city' => 'Kathmandu', 'postcode' => '44600', 'country' => 'NP',
    ]);

    expect($user->fresh()->email)->toBe('owner@example.com.np')
        ->and($staff->fresh()->email)->toBe($staff->email);
});

test('a login without a password sets one through the set-password link, then can change its email', function () {
    $user = passwordlessLogin();
    $this->actingAs($user);
    $token = Password::broker(config('fortify.passwords'))->createToken($user);

    $this->post(route('password.set.store', $token), ['password' => 'a-Strong-pass-123!', 'password_confirmation' => 'a-Strong-pass-123!'])
        ->assertRedirect(route('profile.edit'));
    $this->actingAs($user->fresh())->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('auth.user.has_password', true));

    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])->assertSessionHasErrors('password_confirmation');
    $this->post(route('password.confirm.store'), ['password' => 'a-Strong-pass-123!'])->assertSessionHasNoErrors();
    $this->patch(route('profile.update'), ['name' => 'Asha', 'email' => 'asha.new@example.com'])->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('asha.new@example.com');
});
