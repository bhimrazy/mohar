<?php

namespace App\Modules\Notifications\Services;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Mail\MailFooter;
use App\Modules\Notifications\Mail\MailFrame;
use App\Modules\Notifications\Mail\MailFramer;
use App\Modules\Notifications\Mail\TextLayout;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\EmailTemplateTranslation;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Support\EmailBody;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Modules\Notifications\Support\SupportAddresses;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Renders a template for one recipient: subject + body through the sandboxed engine, wrapped in the brand mail
 * layout (resources/views/vendor/mail, CSS inlined), with a text part and, for mail with a one-time link, a
 * redacted copy for the log (spec §3, §2).
 */
class EmailComposer
{
    public const FIXED_SUPPORT_SUBJECT = '[#{{ ticket.number }}] {{ ticket.subject }}';

    public function __construct(
        private TemplateEngine $engine,
        private Markdown $markdown,
        private Settings $settings,
        private MailFramer $framer,
        private SupportAddresses $addresses,
    ) {}

    /**
     * The one rendering path for stored templates (sends, previews of saved or edited text, test sends).
     * Secret-bearing system templates go through the structure-only renderer; every other template renders
     * free-form Markdown with secret fields removed from its context (SecretTemplatePolicy).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    public function compose(EmailTemplate $template, string $locale, array $context, bool $carriesSecret = false): ComposedEmail
    {
        return AccountLinks::withAppOrigin(fn (): ComposedEmail => $this->composeTemplate($template, $locale, $context, $carriesSecret));
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    private function composeTemplate(EmailTemplate $template, string $locale, array $context, bool $carriesSecret): ComposedEmail
    {
        $translation = $template->translationFor($locale, $this->defaultLocale());

        if ($translation === null) {
            throw new TemplateSyntaxError("Template [{$template->key}] has no text for the default locale.");
        }

        if (SecretTemplatePolicy::isSecretTemplate($template->key)) {
            return $this->composeSecret($template, $translation, $context, $this->addresses->replyTo($template->type, $context));
        }

        // Fail closed: a one-time link only ever goes out in one of the fixed secret templates.
        if ($carriesSecret) {
            throw new TemplateSyntaxError("Template [{$template->key}] cannot carry a one-time link.");
        }

        return $this->composeSource(
            $template->hasFixedSubject() ? self::FIXED_SUPPORT_SUBJECT : $translation->subject,
            $translation->body,
            $context,
            $translation->locale,
            $template->plain_text_only,
            $this->preferencesUrl($template),
            $template->key,
            $translation->action_label,
            $translation->preheader,
            $this->addresses->replyTo($template->type, $context),
        );
    }

    /**
     * Free-form Markdown (non-secret templates, ad-hoc mail). Its text never gets a SensitiveValue. A client ticket
     * template ($templateKey in SecretTemplatePolicy::SYSTEM_ACTIONS) gets a system button for the ticket link
     * ($actionLabel, plain text), added after the mail is built; see withSystemAction().
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    public function composeSource(string $subject, string $body, array $context, string $locale, bool $plainTextOnly = false, ?string $preferencesUrl = null, ?string $templateKey = null, ?string $actionLabel = null, ?string $preheader = null, ?string $supportEmail = null): ComposedEmail
    {
        return AccountLinks::withAppOrigin(fn (): ComposedEmail => $this->composeFreeForm($subject, $body, $context, $locale, $plainTextOnly, $preferencesUrl, $templateKey, $actionLabel, $preheader, $supportEmail));
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    private function composeFreeForm(string $subject, string $body, array $context, string $locale, bool $plainTextOnly, ?string $preferencesUrl, ?string $templateKey, ?string $actionLabel, ?string $preheader, ?string $supportEmail): ComposedEmail
    {
        $actionPath = $templateKey !== null ? SecretTemplatePolicy::systemActionPath($templateKey) : null;
        $action = $actionPath !== null ? data_get($context, $actionPath) : null;
        $isTicketOwner = $this->isTicketOwner($context);
        $labelContext = SecretTemplatePolicy::textContext($context);
        $context = SecretTemplatePolicy::neutraliseUntrusted(SecretTemplatePolicy::prepareContext($context));
        $options = $this->options($locale, $context);
        // Only the template's own action lines can become buttons: they are tagged before any value is filled in.
        $actionToken = 'zzbtn'.bin2hex(random_bytes(12));

        $renderedSubject = $this->engine->render($subject, $context, false, $options);
        $html = $this->engine->render(EmailBody::markActions($body, $actionToken), $context, true, $options);
        $bodyHtml = EmailBody::html($html->output);
        $preview = $this->preheader((string) $preheader, $context, $options) ?? EmailBody::preheader($bodyHtml);
        $frame = $this->framer->frame($templateKey, $context, $locale);
        $url = $action instanceof SensitiveValue ? $action->value : (is_string($action) ? $action : '');
        $warnings = array_values(array_unique([...$renderedSubject->warnings, ...$html->warnings]));
        $subjectLine = $this->subjectLine($renderedSubject->output);
        $layout = fn (string $subject, ?string $preheader): array => ['subject' => $subject, 'preheader' => $preheader, 'frame' => $frame, 'preferencesUrl' => $preferencesUrl, 'supportEmail' => $supportEmail, 'actionToken' => $actionToken];

        if ($url === '') {
            $textParts = EmailBody::textParts($bodyHtml, $actionToken);
            $wrappedHtml = $plainTextOnly ? null : $this->wrapHtml($bodyHtml, $layout($subjectLine, $preview));
            $wrappedText = $this->wrapText($textParts, $layout($subjectLine, $preview));

            return new ComposedEmail($subjectLine, $wrappedHtml, $wrappedText, $wrappedHtml, $wrappedText, false, $warnings);
        }

        $label = trim($this->engine->render(SecretTemplatePolicy::plainCopy((string) $actionLabel), $labelContext, false, $options)->output) ?: __('View your ticket');

        if (! $action instanceof SensitiveValue) {
            $textParts = EmailBody::textParts($bodyHtml, $actionToken);
            [$sentHtml, $sentText] = $this->withSystemAction($bodyHtml, $textParts, $url, $label, $plainTextOnly, $layout($subjectLine, $preview));

            return new ComposedEmail($subjectLine, $sentHtml, $sentText, $sentHtml, $sentText, false, $warnings);
        }

        // A guest link. Staff and template content is masked (on the secret itself, in any encoding) and reduced to
        // the allow-list; the sanitised HTML is the final form of that content (the layout prints it as is).
        $secret = $action->secret();
        $subjectLine = $this->subjectLine(SecretTemplatePolicy::maskValues($renderedSubject->output, [$secret]));
        $preview = $preview === null ? null : SecretTemplatePolicy::maskValues($preview, [$secret]);
        $bodyHtml = SecretTemplatePolicy::sanitizeStaffHtml($bodyHtml, [$secret]);
        // The text part comes from the sanitised HTML: a link dropped there is dropped here too.
        $textParts = self::maskParts(EmailBody::textParts($bodyHtml, $actionToken), [$secret]);

        if (! SecretTemplatePolicy::isAllowedLink($url)) {
            $this->blocked((string) $templateKey, 'The ticket link is not on APP_URL.');
        }

        if (! $isTicketOwner) {
            // Copies to other addresses (ticket CCs) never carry the private link (mail with a secret is never copied).
            $note = __('The ticket owner has the private link to view this ticket online.');
            $sentHtml = $plainTextOnly ? null : $this->wrapHtml($bodyHtml."\n<p>".e($note).'</p>', $layout($subjectLine, $preview));
            $sentText = $this->wrapText([...$textParts, 'notes' => trim($textParts['notes']."\n\n".$note)], $layout($subjectLine, $preview));
            $composed = new ComposedEmail($subjectLine, $sentHtml, $sentText, $sentHtml, $sentText, true, $warnings, $subjectLine);
            $this->assertPlacement($composed, $url, $secret, button: false, templateKey: (string) $templateKey);

            return $composed;
        }

        [$sentHtml, $sentText] = $this->withSystemAction($bodyHtml, $textParts, $url, $label, $plainTextOnly, $layout($subjectLine, $preview));
        $composed = new ComposedEmail(
            subject: $subjectLine,
            html: $sentHtml,
            text: $sentText,
            storedHtml: $sentHtml === null ? null : str_replace(e($url), '••••', $sentHtml),
            storedText: str_replace($url, '••••', $sentText),
            hasRedactions: true,
            warnings: $warnings,
            storedSubject: $subjectLine,
        );
        $this->assertPlacement($composed, $url, $secret, button: true, templateKey: (string) $templateKey);

        return $composed;
    }

    /**
     * The system button for a template's link, placed last: the mail is built (layout, CSS inlining) with a random
     * token in the layout's action slot, and only then is the token replaced by the link. The text part gets a
     * system "Label: url" line.
     *
     * @param  array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}  $textParts
     * @param  array<string, mixed>  $layout
     * @return array{0: string|null, 1: string} [html, text]
     *
     * @throws TemplateSyntaxError
     */
    private function withSystemAction(string $bodyHtml, array $textParts, string $url, string $label, bool $plainTextOnly, array $layout): array
    {
        $text = $this->wrapText($textParts, $layout, $label.': '.$url);
        $token = SecretTemplatePolicy::actionToken();

        if ($plainTextOnly) {
            return [null, $text];
        }

        $built = $this->wrapHtml($bodyHtml, $layout, EmailBody::button($token, $label));

        if (substr_count($built, $token) !== 1) {
            throw new TemplateSyntaxError('The system button slot is missing or repeated.');
        }

        return [str_replace($token, e($url), $built), $text];
    }

    /**
     * @param  array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}  $parts
     * @param  list<string>  $secrets
     * @return array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}
     */
    private static function maskParts(array $parts, array $secrets): array
    {
        $mask = fn (?string $text): ?string => $text === null ? null : SecretTemplatePolicy::maskValues($text, $secrets);
        $link = fn (?array $link): ?array => $link === null ? null : ['url' => (string) $mask($link['url']), 'label' => (string) $mask($link['label'])];

        return [
            'title' => $mask($parts['title']),
            'intro' => (string) $mask($parts['intro']),
            'action' => $link($parts['action']),
            'secondary' => $link($parts['secondary']),
            'facts' => array_map(fn (array $row): array => [(string) $mask($row[0]), (string) $mask($row[1])], $parts['facts']),
            'notes' => (string) $mask($parts['notes']),
        ];
    }

    /**
     * The operator's preheader, rendered as plain text; null when the template has none.
     *
     * @param  array<string, mixed>  $context
     * @param  array{locale: string, date_format: string, currency: string|null}  $options
     */
    private function preheader(string $source, array $context, array $options): ?string
    {
        if (trim($source) === '') {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', $this->engine->render($source, $context, false, $options)->output));

        return $text === '' ? null : mb_strimwidth($text, 0, 200, '…');
    }

    /**
     * The final check over every outgoing and stored field of mail with a secret: subject (header), HTML (preheader
     * included), text, and the log copies. The secret — in any case or encoding — may appear only once in the HTML,
     * as the href of the system button (both parsers), and once in the text part, on the system line; nowhere else.
     * Otherwise nothing is sent and a security event is logged.
     *
     * @throws TemplateSyntaxError
     */
    private function assertPlacement(ComposedEmail $composed, string $url, string $secret, bool $button, string $templateKey): void
    {
        $count = fn (?string $field): int => $field === null ? 0 : SecretTemplatePolicy::secretCount($field, $secret);
        $htmlOk = $composed->html === null || ($button ? SecretTemplatePolicy::placedOnlyAsSystemButton($composed->html, $url, $secret) : $count($composed->html) === 0);
        $textOk = $count($composed->text) === ($button ? 1 : 0) && (! $button || str_contains($composed->text, ': '.$url));

        if (! $htmlOk || ! $textOk || $count($composed->subject) + $count($composed->storedSubject) + $count($composed->storedHtml) + $count($composed->storedText) !== 0) {
            $this->blocked($templateKey, 'The private link appears outside the system button.');
        }
    }

    /**
     * Whether this recipient is the ticket's requester (the one person whose copy may carry the guest link).
     *
     * @param  array<string, mixed>  $context
     */
    private function isTicketOwner(array $context): bool
    {
        $recipient = data_get($context, 'recipient.email');
        $requester = data_get($context, 'ticket.requester_email');

        return is_string($recipient) && is_string($requester) && $requester !== '' && mb_strtolower(trim($recipient)) === mb_strtolower(trim($requester));
    }

    /**
     * Fail closed: the mail is not sent (the caller records it as failed) and, for a sensitive link, the attempt is a
     * security event.
     *
     * @throws TemplateSyntaxError
     */
    private function blocked(string $templateKey, string $reason): never
    {
        app(ActivityLogger::class)->log('Email blocked: access link placement check failed', properties: ['template' => $templateKey, 'reason' => $reason], event: 'security');

        throw new TemplateSyntaxError($reason);
    }

    /**
     * Structure-only rendering for reset, verification and invitation mail: plain-text heading, paragraphs and
     * button label (allow-listed fields only, URL-like text removed), and a system-rendered button and text
     * line for the one-time link, which must be on a configured host. The log copy shows the link as ••••.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    private function composeSecret(EmailTemplate $template, EmailTemplateTranslation $translation, array $context, ?string $supportEmail): ComposedEmail
    {
        $url = SecretTemplatePolicy::actionUrl($template->key, $context);
        $textContext = SecretTemplatePolicy::textContext($context);
        $options = $this->options($translation->locale, $context);
        $plain = fn (?string $source): string => trim($this->engine->render(SecretTemplatePolicy::plainCopy((string) $source), $textContext, false, $options)->output);

        $heading = $plain($translation->heading);
        $label = $plain($translation->action_label) ?: __('Continue');
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $plain($translation->body)) ?: []), fn (string $paragraph): bool => $paragraph !== ''));
        $subject = $this->subjectLine($plain($translation->subject));
        $preheader = $plain($translation->preheader);
        $first = array_slice($paragraphs, 0, 1);
        $rest = array_slice($paragraphs, 1);
        $textParts = ['title' => $heading !== '' ? $heading : null, 'intro' => implode("\n\n", $first), 'action' => null, 'secondary' => null, 'facts' => [], 'notes' => implode("\n\n", $rest)];
        $paragraphHtml = fn (array $list): string => implode("\n", array_map(fn (string $paragraph): string => '<p>'.nl2br(e($paragraph), false).'</p>', $list));
        $layout = [
            'subject' => $subject,
            'preheader' => $preheader !== '' ? $preheader : ($first[0] ?? null),
            'frame' => $this->framer->frame($template->key, $textContext, $translation->locale),
            'preferencesUrl' => null,
            'supportEmail' => $supportEmail,
            'notes' => $paragraphHtml($rest),
        ];

        // The button goes into the layout's system action slot; the remaining paragraphs are the record card's notes.
        $build = function (string $link) use ($heading, $label, $first, $template, $layout, $textParts, $paragraphHtml): array {
            $html = ($heading !== '' ? '<h1>'.e($heading).'</h1>'."\n" : '').$paragraphHtml($first);

            return [
                $template->plain_text_only ? null : $this->wrapHtml($html, $layout, EmailBody::button($link, $label)),
                $this->wrapText($textParts, $layout, $label.': '.$link),
            ];
        };

        [$html, $text] = $build($url);
        [$storedHtml, $storedText] = $build('••••');
        $composed = new ComposedEmail(
            subject: $subject,
            html: $html,
            text: $text,
            storedHtml: $storedHtml,
            storedText: $storedText,
            hasRedactions: true,
            storedSubject: $subject,
        );
        $this->assertPlacement($composed, $url, SecretTemplatePolicy::actionSecret($template->key, $context), button: true, templateKey: $template->key);

        return $composed;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{locale: string, date_format: string, currency: string|null}
     */
    private function options(string $locale, array $context): array
    {
        return [
            'locale' => $locale,
            'date_format' => DateFormats::current(),
            'currency' => is_string($context['client']['currency'] ?? null) ? $context['client']['currency'] : null,
        ];
    }

    private function subjectLine(string $rendered): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $rendered)), 250, '');
    }

    public function defaultLocale(): string
    {
        return (string) $this->settings->get('notifications.default_locale', config('app.locale', 'en'));
    }

    /**
     * Client mail whose category can be switched off links to the email preferences page (auth mail never does).
     */
    public function preferencesUrl(EmailTemplate $template): ?string
    {
        if ($template->audience !== TemplateAudience::Client || str_starts_with($template->key, 'auth.') || ! Route::has('client.notifications.preferences.edit')) {
            return null;
        }

        return route('client.notifications.preferences.edit');
    }

    /**
     * @param  array<string, mixed>  $layout  subject, preheader, frame, preferencesUrl, supportEmail
     * @param  string|null  $action  system button markup for the layout's action slot (outside the body)
     */
    private function wrapHtml(string $bodyHtml, array $layout, ?string $action = null): string
    {
        return $this->markdown->render('notifications::mail.message', [
            ...$layout,
            'body' => $bodyHtml,
            'action' => $action,
            // The body is final HTML (sanitised where it matters): the layout prints it without a Markdown pass.
            'raw' => true,
        ])->toHtml();
    }

    /**
     * @param  array{title: string|null, intro: string, action: array{url: string, label: string}|null, secondary: array{url: string, label: string}|null, facts: list<array{0: string, 1: string}>, notes: string}  $parts
     * @param  array<string, mixed>  $layout
     */
    private function wrapText(array $parts, array $layout, ?string $actionLine = null): string
    {
        $frame = $layout['frame'] ?? null;
        $frame = $frame instanceof MailFrame ? $frame : new MailFrame;
        $preferencesUrl = is_string($layout['preferencesUrl'] ?? null) ? $layout['preferencesUrl'] : null;
        $supportEmail = is_string($layout['supportEmail'] ?? null) ? $layout['supportEmail'] : null;

        return TextLayout::render($parts, $frame, MailFooter::make($preferencesUrl, $supportEmail, $frame), $actionLine);
    }
}
