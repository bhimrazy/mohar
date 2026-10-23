<?php

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Notifications\LoginEmailNotice;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('hasPassword', false));
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
