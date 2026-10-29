<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Contracts\Branding\BrandProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Platform\Notifications\MailServerTest;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Modules\Platform\Support\MailServerSettings;
use App\Support\Integrations\Redactor;
use Aws\Exception\CredentialsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\Mailer\Exception\ExceptionInterface as MailerException;

/**
 * Sends one test email now, with the saved mail server settings, and shows the server's answer where staff acted.
 */
class MailTestController extends Controller
{
    /** Test sends per staff member per 10 minutes. */
    public const MAX_ATTEMPTS = 5;

    /** Longest error text shown or logged, in bytes. */
    private const ERROR_BYTES = 1000;

    public function __invoke(Request $request, SettingsCatalog $catalog, MailServerSettings $mail, Redactor $redactor, ActivityLogger $activity, BrandProvider $brand): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $catalog->canEdit($user, MailServerSettings::SECTION), 403);

        $to = (string) $request->validate(['to' => ['required', 'string', 'email', 'max:255']], attributes: ['to' => 'send to'])['to'];
        $key = 'mail-test:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['to' => __('Too many test emails. Try again in :minutes minutes.', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))])]);
        }

        RateLimiter::hit($key, 600);
        $mailer = $mail->registerTestMailer();
        $error = null;
        $refusal = $mailer === null ? null : $mail->relayRefusal();

        if ($refusal !== null) {
            $error = __('The SMTP host is not allowed: :reason', ['reason' => $refusal]);
        } elseif ($mailer === null) {
            $error = __('The mailer ":mailer" is not defined in the mail configuration.', ['mailer' => (string) config('mail.default')]);
        } else {
            try {
                Notification::route('mail', $to)->notifyNow(new MailServerTest($brand->brand()->name, $user->name));
            } catch (MailerException|CredentialsException $exception) {
                // Shown to staff and kept in the activity log, so not reported as an application error.
                $error = $redactor->excerpt(trim($exception->getMessage()), $mail->secrets(), self::ERROR_BYTES);
            }
        }

        $activity->log($error === null ? 'Test email sent' : 'Test email failed', properties: array_filter([
            'recipient' => $to,
            'mailer' => (string) config('mail.default'),
            'error' => $error,
        ]), event: 'settings.mail_test');

        $logOnly = in_array(config("mail.mailers.{$mailer}.transport"), ['log', 'array'], true);
        Inertia::flash('mailTest', [
            'ok' => $error === null,
            'to' => $to,
            'message' => match (true) {
                $error !== null => __('The mail server did not accept the test email.'),
                $logOnly => __('The mailer is set to log only: the test email was written to the application log, not delivered.'),
                default => __('The mail server accepted the test email for :to. Check that inbox (and its spam folder).', ['to' => $to]),
            },
            'error' => $error,
        ]);

        return to_route('admin.platform.settings.edit', ['section' => MailServerSettings::SECTION]);
    }
}
