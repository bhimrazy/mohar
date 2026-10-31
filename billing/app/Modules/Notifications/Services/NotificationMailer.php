<?php

namespace App\Modules\Notifications\Services;

use App\Events\Notifications\EmailFailed;
use App\Events\Notifications\EmailQueued;
use App\Events\Notifications\EmailSuppressed;
use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Data\Recipient;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Enums\StaffCategory;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Jobs\SendEmailMessage;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Settings\SupportAddresses;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single path every email takes (spec §7): resolve template + locale, render, write the log row
 * (dedupe_key makes event replays harmless), then queue the transport job after commit.
 */
class NotificationMailer
{
    public const RENDER_FAILED_TEMPLATE = 'staff.template_render_failed';

    public function __construct(
        private EmailComposer $composer,
        private MergeContext $mergeContext,
        private RecipientResolver $recipients,
        private Settings $settings,
        private SupportAddresses $addresses,
    ) {}

    /**
     * @return list<EmailMessage> one per recipient (new, or already logged for this occurrence)
     */
    public function send(EmailRequest $request): array
    {
        $template = EmailTemplate::query()->with('translations')->where('key', $request->templateKey)->first();

        if ($template === null) {
            // A default template is added by `app:install`; until it runs (or after an operator deletes one) nothing is
            // sent, so say which key was missing. Only the key: the context may hold personal data.
            Log::warning('Email not sent: no template with this key.', ['template_key' => $request->templateKey]);

            return [];
        }

        return $this->sendTemplate($template, $request);
    }

    /**
     * Sends a stored template, or an unsaved one (ad-hoc staff message: logged with template_key null).
     *
     * @return list<EmailMessage> one per recipient: the new message, or the one an earlier handling of the
     *                            same occurrence already logged
     */
    public function sendTemplate(EmailTemplate $template, EmailRequest $request): array
    {
        // Every link in the message (merge context, layout, preferences) is rooted at APP_URL.
        return AccountLinks::withAppOrigin(fn (): array => $this->sendEach($template, $request));
    }

    /**
     * @return list<EmailMessage>
     */
    private function sendEach(EmailTemplate $template, EmailRequest $request): array
    {
        $created = [];

        foreach ($this->recipients->unique($request->recipients) as $recipient) {
            $dedupeKey = sha1($request->eventKey.'|'.$template->key.'|'.$recipient->key());
            $existing = EmailMessage::query()->where('dedupe_key', $dedupeKey)->first();

            // A re-handled occurrence reports the message it already produced instead of sending again.
            $created[] = $existing ?? $this->sendTo($template, $recipient, $request, $dedupeKey);
        }

        return $created;
    }

    /**
     * Re-queues a logged message as a new attempt with its stored (redacted) body.
     */
    public function resend(EmailMessage $message): void
    {
        // The stored copy of mail with a private link has it masked: resending would send a dead button.
        if ($message->has_redactions) {
            throw new \LogicException('Mail that carried a private link is never resent from its stored copy.');
        }

        $message->update(['status' => EmailStatus::Queued, 'failure_reason' => null, 'suppressed_reason' => null, 'queued_at' => now()]);
        SendEmailMessage::dispatch($message->id, null, $message->attempts);
    }

    private function sendTo(EmailTemplate $template, Recipient $recipient, EmailRequest $request, string $dedupeKey): EmailMessage
    {
        $locale = $recipient->locale ?: $this->composer->defaultLocale();
        $isClientMail = $template->audience === TemplateAudience::Client;
        $context = [
            ...$this->mergeContext->base(! $isClientMail),
            ...$request->context,
            ...$this->mergeContext->recipient($recipient),
        ];

        $attributes = [
            'uuid' => (string) Str::uuid(),
            'dedupe_key' => $dedupeKey,
            'template_key' => $template->exists ? $template->key : null,
            'client_id' => $recipient->clientId ?? $request->clientId,
            'user_id' => $recipient->userId,
            'contact_id' => $recipient->contactId,
            'staff_id' => $recipient->staffId,
            'recipient' => $recipient->key(),
            'to' => [$recipient->key()],
            'cc' => $this->addresses($template->cc ?? []),
            'bcc' => $this->addresses([...($template->bcc ?? []), ...($isClientMail ? $this->globalBcc() : [])]),
            'from' => $request->fromEmail ?? $template->from_email ?? $this->addresses->from($template->type),
            'from_name' => $template->from_name ?? $this->addresses->fromName(),
            'reply_to' => $this->addresses->replyTo($template->type, $context),
            'locale' => $locale,
            'related_type' => $request->relatedType,
            'related_id' => $request->relatedId,
            'queued_at' => now(),
        ];

        try {
            $composed = $this->composer->compose($template, $locale, $context, $request->carriesSecret);
        } catch (TemplateSyntaxError $error) {
            if ($template->carriesSecret() || $request->carriesSecret) {
                $attributes['cc'] = null;
                $attributes['bcc'] = null;
            }

            $message = EmailMessage::query()->createOrFirst(['dedupe_key' => $dedupeKey], [...$attributes, 'subject' => Str::limit($template->name, 250, ''), 'status' => EmailStatus::Failed, 'failure_reason' => $error->getMessage()]);

            if (! $message->wasRecentlyCreated) {
                return $message;
            }

            EmailFailed::dispatch($message->id, $template->key, $message->client_id);
            $this->alertRenderFailure($template, $error, $request->eventKey);

            return $message;
        }

        // Secret-bearing mail goes to its recipient only: no template CC/BCC, no global BCC (B1).
        if ($template->carriesSecret() || $request->carriesSecret || $composed->hasRedactions) {
            $attributes['cc'] = null;
            $attributes['bcc'] = null;
        }

        $suppressedReason = match (true) {
            ! $template->is_enabled && ! $template->isAlwaysSent() => 'template_disabled',
            (bool) $this->settings->get('notifications.mail_disabled', false) => 'mail_disabled',
            // Demo data replays months of history; it is logged for the demo but never sent.
            DemoSeeder::running() => 'demo_data',
            default => null,
        };

        // createOrFirst: a concurrent handler of the same occurrence loses the race quietly (unique dedupe_key).
        $message = EmailMessage::query()->createOrFirst(['dedupe_key' => $dedupeKey], [
            ...$attributes,
            'subject' => $composed->storedSubject ?? $composed->subject,
            'body_html' => $composed->storedHtml,
            'body_text' => $composed->storedText,
            'has_redactions' => $composed->hasRedactions,
            'status' => $suppressedReason === null ? EmailStatus::Queued : EmailStatus::Suppressed,
            'suppressed_reason' => $suppressedReason,
        ]);

        if (! $message->wasRecentlyCreated) {
            return $message;
        }

        if ($suppressedReason !== null) {
            EmailSuppressed::dispatch($message->id, $template->key, $message->client_id, $suppressedReason);

            return $message;
        }

        EmailQueued::dispatch($message->id, $template->key, $message->client_id);
        SendEmailMessage::dispatch($message->id, $this->unredacted($composed))->afterCommit();

        return $message;
    }

    /**
     * The real content only travels in the (encrypted) job when the stored copy differs from it.
     *
     * @return array{subject: string, html: string|null, text: string}|null
     */
    private function unredacted(ComposedEmail $composed): ?array
    {
        return $composed->hasRedactions ? ['subject' => $composed->subject, 'html' => $composed->html, 'text' => $composed->text] : null;
    }

    /**
     * Tells staff (system category) that a template is broken, once per template and event.
     */
    private function alertRenderFailure(EmailTemplate $template, TemplateSyntaxError $error, string $eventKey): void
    {
        if ($template->key === self::RENDER_FAILED_TEMPLATE) {
            return;
        }

        $this->send(new EmailRequest(
            self::RENDER_FAILED_TEMPLATE,
            $this->recipients->staff(StaffCategory::System),
            ['template' => [
                'key' => $template->key,
                'name' => $template->name,
                'error' => $error->getMessage(),
                'admin_url' => $this->mergeContext->link('admin.notifications.templates.edit', $template->id),
            ]],
            'render-failed|'.$template->key.'|'.$eventKey,
            relatedType: 'email_template',
            relatedId: $template->id,
        ));
    }

    /**
     * @return list<string>
     */
    private function globalBcc(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->settings->get('notifications.mail_bcc', '')))));
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>|null
     */
    private function addresses(array $addresses): ?array
    {
        $valid = array_values(array_unique(array_map(
            fn (string $address): string => mb_strtolower(trim($address)),
            array_filter($addresses, fn (string $address): bool => filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false),
        )));

        return $valid === [] ? null : $valid;
    }
}
