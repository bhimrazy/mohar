<?php

use App\Models\User;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\SampleContext;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Notifications\ClientInvitationNotification;
use App\Modules\Platform\Notifications\SecurityNotice;
use App\Modules\Platform\Notifications\StaffInvitationNotification;
use App\Notifications\SetPasswordNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/helpers.php';

/*
 * Every email the system sends goes through one branded layout (resources/views/vendor/mail) with the
 * brand from BrandProvider and a plain-text part. Adding a new mail class without covering it here fails.
 */

const LAYOUT_MARKER = 'data-mail-layout="brand"';

/** Mail classes in app/ and how this file covers them. */
const COVERED_MAIL_CLASSES = [
    TemplatedMail::class,
    SecurityNotice::class,
    StaffInvitationNotification::class,
    ClientInvitationNotification::class,
    SetPasswordNotification::class,
];

beforeEach(fn () => app(Settings::class)->set('core.company_name', 'Acme Hosting'));

it('knows every mail class in the application', function () {
    $found = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php')->contains('/extends (Mailable|Notification)\b/') as $file) {
        preg_match('/namespace ([^;]+);/', $file->getContents(), $namespace);
        $found[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    sort($found);
    $covered = COVERED_MAIL_CLASSES;
    sort($covered);

    expect($found)->toBe($covered);
});

it('renders every template with the branded layout, brand name and a text part', function () {
    seedEmailTemplates();
    $composer = app(EmailComposer::class);
    $samples = app(SampleContext::class);

    foreach (EmailTemplate::query()->with('translations')->orderBy('key')->get() as $template) {
        $composed = $composer->compose($template, 'en', $samples->build($template->audience === TemplateAudience::Staff));

        expect($composed->html)->toContain(LAYOUT_MARKER, 'Acme Hosting')->not->toContain('Laravel')
            ->and($composed->text)->not->toBe('')->toContain('Acme Hosting')->not->toContain('{{', '|---');
    }
});

it('shows the email preferences link only where the category can be switched off', function () {
    seedEmailTemplates();
    $composer = app(EmailComposer::class);
    $sample = app(SampleContext::class)->build();
    $render = fn (string $key): string => (string) $composer->compose(EmailTemplate::query()->with('translations')->where('key', $key)->sole(), 'en', $sample)->html;

    expect($render('invoice.created'))->toContain(route('client.notifications.preferences.edit'))
        ->and($render('auth.password_reset'))->not->toContain(route('client.notifications.preferences.edit'));
});

it('turns the first standalone link into the primary button and tables into the key-facts block', function () {
    $html = (string) app(EmailComposer::class)->composeSource('Hi', "# Title\n\nSummary line.\n\n| Amount | \$5.00 |\n|---|---|\n\n[Pay invoice](https://billing.test/i/1)", [], 'en')->html;

    expect($html)->toContain('f-k', 'f-v', 'class="button"', 'Pay invoice', 'Summary line.')
        ->and(substr_count($html, 'class="preheader"'))->toBe(1);
});

it('renders framework and module notifications with the branded layout', function (Closure $notification) {
    $user = User::factory()->create();
    $message = $notification($user)->toMail($user);

    expect($message)->toBeInstanceOf(MailMessage::class);

    $html = (string) $message->render();
    $text = app(Markdown::class)->renderText('notifications::email', $message->data())->toHtml();

    expect($html)->toContain(LAYOUT_MARKER, 'Acme Hosting')->not->toContain('Laravel')
        ->and($text)->toContain('Acme Hosting')->not->toContain('Laravel');
})->with([
    'password reset' => [fn (User $user) => new ResetPassword('token')],
    'email verification' => [fn (User $user) => new VerifyEmail],
    'security notice' => [fn (User $user) => new SecurityNotice('Your two-factor authentication was reset', ['An administrator reset it.'])],
    'staff invitation' => [fn (User $user) => new StaffInvitationNotification(StaffInvitation::factory()->create(), 'token')],
    'client invitation' => [fn (User $user) => new ClientInvitationNotification('Acme Studio', url('/account-invitation/token'), now()->addWeek())],
]);
