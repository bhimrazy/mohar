<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Auth\AuthMailer;
use App\Data\Notifications\SensitiveValue;
use App\Models\User;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AuthMailer bound by the Notifications module: reset and verification mail from editable, logged,
 * branded templates — never copied to anyone (secret-bearing). Links are built on APP_URL; one outside the
 * configured origins is never mailed. When no message could be queued (template missing or unrenderable),
 * Laravel's own notification (same, already-checked link) goes out instead, so access mail is never lost.
 */
class TemplatedAuthMailer implements AuthMailer
{
    public function __construct(
        private NotificationMailer $mailer,
        private RecipientResolver $recipients,
        private MergeContext $context,
        private LaravelAuthMailer $fallback,
    ) {}

    public function sendPasswordReset(User $user, #[\SensitiveParameter] string $token): void
    {
        // AccountLinks (APP_URL) unless the application configured its own builder, which is then host-checked.
        $url = ResetPassword::$createUrlCallback !== null
            ? (string) call_user_func(ResetPassword::$createUrlCallback, $user, $token)
            : AccountLinks::passwordReset($user, $token);

        if (! $this->linkAllowed($url, $user, 'password reset')) {
            return;
        }

        $broker = (string) config('auth.defaults.passwords', 'users');
        $expiresAt = now()->addMinutes((int) config("auth.passwords.{$broker}.expire", 60))->format(DateFormats::current().' H:i');

        $sent = $this->send($user, $user->is_staff ? 'staff.password_reset' : 'auth.password_reset', [
            'auth' => ['reset_url' => new SensitiveValue($url), 'expires_at' => $expiresAt],
        ], 'auth-reset|'.$user->id.'|'.hash('sha256', $token));

        if (! $this->handled($sent)) {
            $this->fallback->sendPasswordReset($user, $token);
        }
    }

    public function sendEmailVerification(User $user): void
    {
        $url = VerifyEmail::$createUrlCallback !== null
            ? (string) call_user_func(VerifyEmail::$createUrlCallback, $user)
            : AccountLinks::emailVerification($user);

        if (! $this->linkAllowed($url, $user, 'email verification')) {
            return;
        }

        $sent = $this->send($user, 'auth.email_verification', ['auth' => ['verify_url' => new SensitiveValue($url)]], 'auth-verify|'.$user->id.'|'.hash('sha256', $url));

        if (! $this->handled($sent)) {
            $this->fallback->sendEmailVerification($user);
        }
    }

    /**
     * Fail closed: a link outside the configured origins (APP_URL, notifications.auth_link_host) is never mailed —
     * not by the template, not by the Laravel fallback. The attempt is recorded as a security event (host only).
     */
    private function linkAllowed(string $url, User $user, string $kind): bool
    {
        if (SecretTemplatePolicy::isAllowedLink($url)) {
            return true;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $reason = SecretTemplatePolicy::accountLinkProblem() ?? 'The link is not on APP_URL or notifications.auth_link_host.';
        Log::warning('Account email blocked: its link is not on a configured host.', ['kind' => $kind, 'host' => $host, 'reason' => $reason, 'user_id' => $user->id]);
        app(ActivityLogger::class)->log('Account email blocked: link host not allowed', $user, properties: ['kind' => $kind, 'host' => mb_substr($host, 0, 255), 'reason' => $reason], event: 'security');

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<EmailMessage>
     */
    private function send(User $user, string $templateKey, array $context, string $occurrenceKey): array
    {
        try {
            $client = $user->is_staff ? null : $user->clients()->orderBy('clients.id')->first();
            $recipient = $this->recipients->user($user->id, $client);

            if ($recipient === null) {
                return [];
            }

            return $this->mailer->send(new EmailRequest(
                $templateKey,
                [$recipient],
                [
                    ...$this->context->client($client?->id),
                    'user' => ['first_name' => $recipient->firstName(), 'email' => $user->email],
                    ...($user->is_staff ? ['staff' => ['first_name' => $recipient->firstName()]] : []),
                    ...$context,
                ],
                $occurrenceKey,
                $client?->id,
                'user',
                $user->id,
                carriesSecret: true,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Handled once a message is queued, sent or deliberately suppressed (an earlier request for the same
     * token counts); nothing at all or only failed rows means the fallback must send.
     *
     * @param  list<EmailMessage>  $messages
     */
    private function handled(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message->status !== EmailStatus::Failed) {
                return true;
            }
        }

        return false;
    }
}
