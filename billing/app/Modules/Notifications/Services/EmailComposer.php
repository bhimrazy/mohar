<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Core\Support\AccountLinks;
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
        );
    }

    /**
     * Free-form Markdown (non-secret templates, ad-hoc mail). Sensitive values reach it only if allowed for this
     * template ($templateKey; never ad-hoc), and only as whole link targets; the log copy masks them.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    public function composeSource(string $subject, string $body, array $context, string $locale, bool $plainTextOnly = false, ?string $preferencesUrl = null, ?string $templateKey = null): ComposedEmail
    {
        return AccountLinks::withAppOrigin(fn (): ComposedEmail => $this->composeFreeForm($subject, $body, $context, $locale, $plainTextOnly, $preferencesUrl, $templateKey));
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    private function composeFreeForm(string $subject, string $body, array $context, string $locale, bool $plainTextOnly, ?string $preferencesUrl, ?string $templateKey): ComposedEmail
    {
        [$context, $sensitive] = SecretTemplatePolicy::prepareContext($context, $templateKey);
        $options = $this->options($locale, $context);

        $renderedSubject = $this->engine->render($subject, $context, false, $options);
        $html = $this->engine->render($body, $context, true, $options);
        $text = $this->engine->render($body, $context, false, $options);
        $usedSensitive = array_filter(array_keys($sensitive), fn (string $placeholder): bool => str_contains($html->output.$text->output, $placeholder)) !== [];

        $parts = function (bool $reveal) use ($html, $text, $sensitive, $plainTextOnly, $preferencesUrl): array {
            return [
                $plainTextOnly ? null : $this->wrapHtml(EmailBody::html(SecretTemplatePolicy::placeSensitive($html->output, $sensitive, $reveal)), $preferencesUrl),
                $this->wrapText(EmailBody::text(SecretTemplatePolicy::placeSensitive($text->output, $sensitive, $reveal)), $preferencesUrl),
            ];
        };

        [$wrappedHtml, $wrappedText] = $parts(true);
        [$storedHtml, $storedText] = $usedSensitive ? $parts(false) : [$wrappedHtml, $wrappedText];
        $subjectLine = $this->subjectLine(SecretTemplatePolicy::placeSensitive($renderedSubject->output, $sensitive, false));

        return new ComposedEmail(
            subject: $subjectLine,
            html: $wrappedHtml,
            text: $wrappedText,
            storedHtml: $storedHtml,
            storedText: $storedText,
            hasRedactions: $usedSensitive,
            warnings: array_values(array_unique([...$renderedSubject->warnings, ...$html->warnings])),
        );
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

    private function wrapHtml(string $bodyHtml, ?string $preferencesUrl): string
    {
        return $this->markdown->render('notifications::mail.message', [
            'body' => $bodyHtml,
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
