<?php

namespace App\Modules\Notifications\Services;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\EmailTemplateTranslation;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Support\EmailBody;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
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
            return $this->composeSecret($template, $translation, $context);
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
    public function composeSource(string $subject, string $body, array $context, string $locale, bool $plainTextOnly = false, ?string $preferencesUrl = null, ?string $templateKey = null, ?string $actionLabel = null): ComposedEmail
    {
        return AccountLinks::withAppOrigin(fn (): ComposedEmail => $this->composeFreeForm($subject, $body, $context, $locale, $plainTextOnly, $preferencesUrl, $templateKey, $actionLabel));
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    private function composeFreeForm(string $subject, string $body, array $context, string $locale, bool $plainTextOnly, ?string $preferencesUrl, ?string $templateKey, ?string $actionLabel): ComposedEmail
    {
        $actionPath = $templateKey !== null ? SecretTemplatePolicy::systemActionPath($templateKey) : null;
        $action = $actionPath !== null ? data_get($context, $actionPath) : null;
        $labelContext = SecretTemplatePolicy::textContext($context);
        $context = SecretTemplatePolicy::prepareContext($context);
        $options = $this->options($locale, $context);

        $renderedSubject = $this->engine->render($subject, $context, false, $options);
        $html = $this->engine->render($body, $context, true, $options);
        $text = $this->engine->render($body, $context, false, $options);
        $bodyHtml = EmailBody::html($html->output);
        $bodyText = EmailBody::text($text->output);
        $url = $action instanceof SensitiveValue ? $action->value : (is_string($action) ? $action : '');

        if ($url === '') {
            $wrappedHtml = $plainTextOnly ? null : $this->wrapHtml($bodyHtml, $preferencesUrl);
            $wrappedText = $this->wrapText($bodyText, $preferencesUrl);

            return new ComposedEmail(
                subject: $this->subjectLine($renderedSubject->output),
                html: $wrappedHtml,
                text: $wrappedText,
                storedHtml: $wrappedHtml,
                storedText: $wrappedText,
                hasRedactions: false,
                warnings: array_values(array_unique([...$renderedSubject->warnings, ...$html->warnings])),
            );
        }

        $label = trim($this->engine->render(SecretTemplatePolicy::plainCopy((string) $actionLabel), $labelContext, false, $options)->output) ?: __('View your ticket');
        $sensitive = $action instanceof SensitiveValue;
        [$sentHtml, $sentText, $storedHtml, $storedText] = $this->withSystemAction(
            $sensitive ? SecretTemplatePolicy::sanitizeStaffHtml($bodyHtml, [$url]) : $bodyHtml,
            $sensitive ? SecretTemplatePolicy::maskValues($bodyText, [$url]) : $bodyText, $url, $label, $sensitive, $plainTextOnly, $preferencesUrl, (string) $templateKey,
        );

        return new ComposedEmail(
            subject: $this->subjectLine($renderedSubject->output),
            html: $sentHtml,
            text: $sentText,
            storedHtml: $storedHtml,
            storedText: $storedText,
            hasRedactions: $sensitive,
            warnings: array_values(array_unique([...$renderedSubject->warnings, ...$html->warnings])),
        );
    }

    /**
     * The system button for a template's link, placed last: the mail is built (Markdown, layout, CSS inlining)
     * with a random token in the layout's action slot, and only then is the token replaced by the link. A
     * sensitive link must then appear exactly once, as that button's href, to both parsers — else nothing is sent.
     * The text part gets a system "Label: url" line.
     *
     * @return array{0: string|null, 1: string, 2: string|null, 3: string} [html, text, stored html, stored text]
     *
     * @throws TemplateSyntaxError
     */
    private function withSystemAction(string $bodyHtml, string $bodyText, string $url, string $label, bool $sensitive, bool $plainTextOnly, ?string $preferencesUrl, string $templateKey): array
    {
        if ($sensitive && ! SecretTemplatePolicy::isAllowedLink($url)) {
            $this->blocked($templateKey, 'The ticket link is not on APP_URL.', $sensitive);
        }

        $line = fn (string $link): string => $this->wrapText(trim($bodyText."\n\n".$label.': '.$link), $preferencesUrl);
        $text = $line($url);

        if ($sensitive && substr_count($text, $url) !== 1) {
            $this->blocked($templateKey, 'The text part holds the ticket link more than once.', $sensitive);
        }

        if ($plainTextOnly) {
            return [null, $text, null, $sensitive ? $line('••••') : $text];
        }

        $token = SecretTemplatePolicy::actionToken();
        $built = $this->wrapHtml($bodyHtml, $preferencesUrl, EmailBody::button($token, $label));

        if (substr_count($built, $token) !== 1) {
            $this->blocked($templateKey, 'The system button slot is missing or repeated.', $sensitive);
        }

        $html = str_replace($token, e($url), $built);

        if ($sensitive && ! SecretTemplatePolicy::placedOnlyAsSystemButton($html, $url)) {
            $this->blocked($templateKey, 'The ticket link appears outside the system button.', $sensitive);
        }

        return [$html, $text, $sensitive ? str_replace($token, '••••', $built) : $html, $sensitive ? $line('••••') : $text];
    }

    /**
     * Fail closed: the mail is not sent (the caller records it as failed) and, for a sensitive link, the attempt is a
     * security event.
     *
     * @throws TemplateSyntaxError
     */
    private function blocked(string $templateKey, string $reason, bool $sensitive): never
    {
        if ($sensitive) {
            app(ActivityLogger::class)->log('Email blocked: access link placement check failed', properties: ['template' => $templateKey, 'reason' => $reason], event: 'security');
        }

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
    private function composeSecret(EmailTemplate $template, EmailTemplateTranslation $translation, array $context): ComposedEmail
    {
        $url = SecretTemplatePolicy::actionUrl($template->key, $context);
        $textContext = SecretTemplatePolicy::textContext($context);
        $options = $this->options($translation->locale, $context);
        $plain = fn (?string $source): string => trim($this->engine->render(SecretTemplatePolicy::plainCopy((string) $source), $textContext, false, $options)->output);

        $heading = $plain($translation->heading);
        $label = $plain($translation->action_label) ?: __('Continue');
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $plain($translation->body)) ?: []), fn (string $paragraph): bool => $paragraph !== ''));
        $subject = $this->subjectLine($plain($translation->subject));

        $build = function (string $link) use ($heading, $label, $paragraphs, $template): array {
            $first = array_slice($paragraphs, 0, 1);
            $rest = array_slice($paragraphs, 1);
            $html = ($heading !== '' ? '<h1>'.e($heading).'</h1>'."\n" : '')
                .implode("\n", array_map(fn (string $paragraph): string => '<p>'.nl2br(e($paragraph), false).'</p>', $first))."\n"
                .EmailBody::button($link, $label)."\n"
                .implode("\n", array_map(fn (string $paragraph): string => '<p>'.nl2br(e($paragraph), false).'</p>', $rest));
            $text = implode("\n\n", array_filter([$heading, ...$first, $label.': '.$link, ...$rest], fn (string $part): bool => $part !== ''));

            return [
                $template->plain_text_only ? null : $this->wrapHtml((string) preg_replace("/\n{2,}/", "\n", $html), null),
                $this->wrapText($text, null),
            ];
        };

        [$html, $text] = $build($url);
        [$storedHtml, $storedText] = $build('••••');

        if (($html !== null && ! SecretTemplatePolicy::placedOnlyAsSystemButton($html, $url)) || substr_count($text, $url) !== 1) {
            $this->blocked($template->key, 'The one-time link appears outside the system button.', true);
        }

        return new ComposedEmail(
            subject: $subject,
            html: $html,
            text: $text,
            storedHtml: $storedHtml,
            storedText: $storedText,
            hasRedactions: true,
        );
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
     * @param  string|null  $action  system button markup for the layout's action slot (after the body, outside its
     *                               Markdown pass)
     */
    private function wrapHtml(string $bodyHtml, ?string $preferencesUrl, ?string $action = null): string
    {
        return $this->markdown->render('notifications::mail.message', [
            'body' => $bodyHtml,
            'action' => $action,
            'preheader' => EmailBody::preheader($bodyHtml),
            'preferencesUrl' => $preferencesUrl,
        ])->toHtml();
    }

    private function wrapText(string $plainBody, ?string $preferencesUrl): string
    {
        return trim($this->markdown->renderText('notifications::mail.message', [
            'body' => $plainBody,
            'preferencesUrl' => $preferencesUrl,
        ])->toHtml());
    }
}
