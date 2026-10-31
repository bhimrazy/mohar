<?php

namespace App\Modules\Notifications\Jobs;

use App\Contracts\Billing\BillingDocuments;
use App\Data\Billing\DocumentFile;
use App\Events\Notifications\EmailFailed;
use App\Events\Notifications\EmailSent;
use App\Events\Notifications\EmailSuppressed;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands one logged message to the transport (spec §7): 3 attempts (1, 5, 30 minutes), then failed.
 * The payload is encrypted because it may carry the unredacted body (reset, verification and invitation links).
 * Unique per message while queued or retrying, so a send queued again (a retried composer, a resend) never runs
 * alongside the first; the queued-status check stops one that runs after it.
 */
class SendEmailMessage implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Longer than the three attempts and their backoff, so the lock outlives a slow retry but not a lost job. */
    public int $uniqueFor = 3600;

    public int $tries;

    /** Below the queue connection's retry_after (90 s), so a slow send is never run twice in parallel. */
    public int $timeout = 60;

    /**
     * @param  array{subject: string, html: string|null, text: string}|null  $content  unredacted content; null = the stored copy
     * @param  int  $attemptsBefore  attempts recorded before this send round (resends start a new round)
     */
    public function __construct(
        public int $messageId,
        public ?array $content = null,
        public int $attemptsBefore = 0,
    ) {
        $this->tries = max(1, (int) config('notifications.mail_max_attempts', 3));
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('notifications.mail_backoff_seconds', [60, 300, 1800])));
    }

    public function handle(Settings $settings): void
    {
        $message = EmailMessage::query()->find($this->messageId);

        if ($message === null || $message->status !== EmailStatus::Queued) {
            return;
        }

        if ((bool) $settings->get('notifications.mail_disabled', false)) {
            $message->update(['status' => EmailStatus::Suppressed, 'suppressed_reason' => 'mail_disabled']);
            EmailSuppressed::dispatch($message->id, $message->template_key, $message->client_id, 'mail_disabled');

            return;
        }

        $message->increment('attempts');

        try {
            $sent = Mail::to($message->to)
                ->cc($message->cc ?? [])
                ->bcc($message->bcc ?? [])
                ->send(new TemplatedMail(
                    subjectLine: $this->content['subject'] ?? $message->subject,
                    htmlBody: $this->content !== null ? $this->content['html'] : $message->body_html,
                    plainText: $this->content['text'] ?? (string) $message->body_text,
                    fromEmail: $message->from,
                    fromName: $message->from_name,
                    replyToEmail: $message->reply_to,
                    messageUuid: $message->uuid,
                    automatic: TemplatedMail::isAutomatic($message->template_key),
                    document: $this->document($message),
                ));
        } catch (Throwable $exception) {
            $this->recordFailure($message, $exception);

            return;
        }

        $message->update([
            'status' => EmailStatus::Sent,
            'sent_at' => now(),
            'failure_reason' => null,
            'provider_message_id' => $sent?->getMessageId(),
        ]);
        EmailSent::dispatch($message->id, $message->template_key, $message->client_id, $message->related_type, $message->related_id);
    }

    /**
     * The related record's document, for a template that carries one, in the message's locale. Asked for at each send, so
     * a resend carries it too. A document that cannot be produced is reported and the mail goes without it. One over the size cap is left off (the mail links to it) and logged.
     */
    private function document(EmailMessage $message): ?DocumentFile
    {
        if ($message->related_type === null || $message->related_id === null || ! app(TemplateCatalog::class)->attachesDocument($message->template_key) || ! app()->bound(BillingDocuments::class)) {
            return null;
        }

        try {
            $document = app(BillingDocuments::class)->document($message->related_type, (int) $message->related_id, $message->locale);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Email document could not be produced; the mail is sent without it.', ['email_message_id' => $message->id, 'related_type' => $message->related_type, 'related_id' => $message->related_id]);

            return null;
        }

        $cap = (int) app(Settings::class)->get('notifications.attachment_max_bytes');

        if ($document !== null && $cap > 0 && Storage::disk($document->disk)->size($document->path) > $cap) {
            Log::warning('Email document too large to attach; the mail links to it instead.', ['email_message_id' => $message->id, 'related_type' => $message->related_type, 'related_id' => $message->related_id]);

            return null;
        }

        return $document;
    }

    /**
     * The job died outside handle() (timeout, worker killed, attempts exhausted): never leave the row queued.
     */
    public function failed(?Throwable $exception = null): void
    {
        $message = EmailMessage::query()->find($this->messageId);

        if ($message === null || $message->status !== EmailStatus::Queued) {
            return;
        }

        $message->update(['status' => EmailStatus::Failed, 'failure_reason' => Str::limit($exception?->getMessage() ?? 'The send job did not finish.', 1000)]);
        EmailFailed::dispatch($message->id, $message->template_key, $message->client_id);
    }

    /**
     * Rethrows while attempts remain so the queue retries with backoff; marks the message failed after the last.
     *
     * @throws Throwable
     */
    private function recordFailure(EmailMessage $message, Throwable $exception): void
    {
        $message->refresh();
        $message->failure_reason = Str::limit($exception->getMessage(), 1000);

        if ($message->attempts - $this->attemptsBefore < $this->tries) {
            $message->save();

            throw $exception;
        }

        $message->status = EmailStatus::Failed;
        $message->save();
        EmailFailed::dispatch($message->id, $message->template_key, $message->client_id);
    }
}
