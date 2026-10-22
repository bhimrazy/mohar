<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Notifications\SystemMailer;
use App\Enums\SystemMailOutcome;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Support\MailClock;
use Carbon\CarbonInterface;
use Throwable;

/**
 * SystemMailer bound by the Notifications module: invitations and account security notices go out as editable,
 * logged, branded templates. A message the template system refused (a one-time link that failed its checks, a
 * template that failed to render) is Refused, so no other path sends it; only a template that isn't installed is
 * Unavailable.
 */
class TemplatedSystemMailer implements SystemMailer
{
    public function __construct(
        private NotificationMailer $mailer,
        private RecipientResolver $recipients,
        private MergeContext $context,
    ) {}

    public function send(string $templateKey, User|string $recipient, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): SystemMailOutcome
    {
        if (! EmailTemplate::query()->where('key', $templateKey)->exists()) {
            return SystemMailOutcome::Unavailable;
        }

        try {
            $client = $clientId !== null ? Client::query()->find($clientId) : null;
            $resolved = $recipient instanceof User
                ? $this->recipients->user($recipient->id, $recipient->is_staff ? null : $client)
                : ($this->recipients->addresses([$recipient], $client)[0] ?? null);

            if ($resolved === null) {
                return SystemMailOutcome::Unavailable;
            }

            $messages = $this->mailer->send(new EmailRequest(
                $templateKey,
                [$resolved],
                [...$this->context->client($clientId), ...self::withDates($context)],
                $occurrenceKey,
                $clientId,
                'user',
                $recipient instanceof User ? $recipient->id : null,
                carriesSecret: $carriesSecret,
            ));
        } catch (Throwable $exception) {
            report($exception);

            // Fail closed for mail with a one-time link: an unexpected error is never a reason to send it another way.
            return $carriesSecret ? SystemMailOutcome::Refused : SystemMailOutcome::Unavailable;
        }

        $message = $messages[0] ?? null;

        return match (true) {
            $message === null => SystemMailOutcome::Unavailable,
            $message->status === EmailStatus::Failed => SystemMailOutcome::Refused,
            $message->status === EmailStatus::Suppressed => SystemMailOutcome::Suppressed,
            default => SystemMailOutcome::Taken,
        };
    }

    /**
     * Dates and times the caller passes as Carbon instances, in the one mail format (company time).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function withDates(array $context): array
    {
        array_walk_recursive($context, function (mixed &$value): void {
            if ($value instanceof CarbonInterface) {
                $value = MailClock::dateTime($value);
            }
        });

        return $context;
    }
}
