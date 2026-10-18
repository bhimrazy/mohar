<?php

namespace App\Modules\Notifications\Services;

use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Support\MailClock;
use Carbon\CarbonInterface;
use Throwable;

/**
 * SystemMailer bound by the Notifications module: invitations and account security notices go out as editable,
 * logged, branded templates. Returns false (the caller sends its fallback) when nothing could be queued.
 */
class TemplatedSystemMailer implements SystemMailer
{
    public function __construct(
        private NotificationMailer $mailer,
        private RecipientResolver $recipients,
        private MergeContext $context,
    ) {}

    public function send(string $templateKey, array $recipients, array $context, string $occurrenceKey, ?int $clientId = null, bool $carriesSecret = false): bool
    {
        try {
            $client = $clientId !== null ? Client::query()->find($clientId) : null;
            $resolved = $this->recipients->unique(array_values(array_filter(array_map(
                fn (User|string $recipient): ?Recipient => $recipient instanceof User
                    ? $this->recipients->user($recipient->id, $recipient->is_staff ? null : $client)
                    : ($this->recipients->addresses([$recipient], $client)[0] ?? null),
                $recipients,
            ))));

            if ($resolved === []) {
                return false;
            }

            $messages = $this->mailer->send(new EmailRequest(
                $templateKey,
                $resolved,
                [...$this->context->client($clientId), ...self::withDates($context)],
                $occurrenceKey,
                $clientId,
                'user',
                null,
                carriesSecret: $carriesSecret,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $messages !== [] && count($messages) === count($resolved)
            && array_filter($messages, fn (EmailMessage $message): bool => $message->status === EmailStatus::Failed) === [];
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
