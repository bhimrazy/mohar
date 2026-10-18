<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Auth\AuthMailer;
use App\Models\User;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * AuthMailer bound by the Notifications module: reset and verification mail from editable, logged,
 * branded templates — never copied to anyone (secret-bearing). When no message could be queued (template
 * missing or unrenderable), Laravel's own notification goes out instead, so access mail is never lost.
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
        $url = ResetPassword::$createUrlCallback !== null
            ? (string) call_user_func(ResetPassword::$createUrlCallback, $user, $token)
            : url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));
        $broker = (string) config('auth.defaults.passwords', 'users');
        $expiresAt = now()->addMinutes((int) config("auth.passwords.{$broker}.expire", 60))->format('Y-m-d H:i');

        $sent = $this->send($user, $user->is_staff ? 'staff.password_reset' : 'auth.password_reset', [
            'auth' => ['reset_url' => $url, 'expires_at' => $expiresAt],
        ], 'auth-reset|'.$user->id.'|'.hash('sha256', $token));

        if (! $this->handled($sent)) {
            $this->fallback->sendPasswordReset($user, $token);
        }
    }

    public function sendEmailVerification(User $user): void
    {
        $url = VerifyEmail::$createUrlCallback !== null
            ? (string) call_user_func(VerifyEmail::$createUrlCallback, $user)
            : URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            );

        $sent = $this->send($user, 'auth.email_verification', ['auth' => ['verify_url' => $url]], 'auth-verify|'.$user->id.'|'.hash('sha256', $url));

        if (! $this->handled($sent)) {
            $this->fallback->sendEmailVerification($user);
        }
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
