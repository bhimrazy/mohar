<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Support\EmailBody;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Renders a template for one recipient: subject + Markdown body through the sandboxed engine, wrapped in
 * the brand mail layout (resources/views/vendor/mail, CSS inlined), with a text part and a redacted copy
 * for the log (spec §3, §2).
 */
class EmailComposer
{
    public const FIXED_SUPPORT_SUBJECT = '[#{{ ticket.number }}] {{ ticket.subject }}';

    public function __construct(
        private TemplateEngine $engine,
        private MergeContext $mergeContext,
        private Markdown $markdown,
        private Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    public function compose(EmailTemplate $template, string $locale, array $context): ComposedEmail
    {
        $translation = $template->translationFor($locale, $this->defaultLocale());

        if ($translation === null) {
            throw new TemplateSyntaxError("Template [{$template->key}] has no text for the default locale.");
        }

        return $this->composeSource(
            $template->hasFixedSubject() ? self::FIXED_SUPPORT_SUBJECT : $translation->subject,
            $translation->body,
            $context,
            $translation->locale,
            $template->plain_text_only,
            $this->preferencesUrl($template),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    public function composeSource(string $subject, string $body, array $context, string $locale, bool $plainTextOnly = false, ?string $preferencesUrl = null): ComposedEmail
    {
        $options = [
            'locale' => $locale,
            'date_format' => (string) $this->settings->get('core.date_format', 'Y-m-d'),
            'currency' => is_string($context['client']['currency'] ?? null) ? $context['client']['currency'] : null,
        ];
        [$redactedContext, $hasRedactions] = $this->mergeContext->redact($context);

        $renderedSubject = $this->engine->render($subject, $context, false, $options);
        $html = $this->engine->render($body, $context, true, $options);
        $text = $this->engine->render($body, $context, false, $options);

        $wrappedHtml = $plainTextOnly ? null : $this->wrapHtml($html->output, $preferencesUrl);
        $wrappedText = $this->wrapText($text->output, $preferencesUrl);

        return new ComposedEmail(
            subject: $this->subjectLine($renderedSubject->output),
            html: $wrappedHtml,
            text: $wrappedText,
            storedHtml: $hasRedactions && ! $plainTextOnly ? $this->wrapHtml($this->engine->render($body, $redactedContext, true, $options)->output, $preferencesUrl) : $wrappedHtml,
            storedText: $hasRedactions ? $this->wrapText($this->engine->render($body, $redactedContext, false, $options)->output, $preferencesUrl) : $wrappedText,
            hasRedactions: $hasRedactions,
            warnings: array_values(array_unique([...$renderedSubject->warnings, ...$html->warnings])),
            storedSubject: $hasRedactions ? $this->subjectLine($this->engine->render($subject, $redactedContext, false, $options)->output) : null,
        );
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

    private function wrapHtml(string $markdownBody, ?string $preferencesUrl): string
    {
        $body = EmailBody::html($markdownBody);

        return $this->markdown->render('notifications::mail.message', [
            'body' => $body,
            'preheader' => EmailBody::preheader($body),
            'preferencesUrl' => $preferencesUrl,
        ])->toHtml();
    }

    private function wrapText(string $plainBody, ?string $preferencesUrl): string
    {
        return trim($this->markdown->renderText('notifications::mail.message', [
            'body' => EmailBody::text($plainBody),
            'preferencesUrl' => $preferencesUrl,
        ])->toHtml());
    }
}
