<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\ComposedEmail;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use Illuminate\Mail\Markdown;
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
        );
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError
     */
    public function composeSource(string $subject, string $body, array $context, string $locale, bool $plainTextOnly = false): ComposedEmail
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

        $wrappedHtml = $plainTextOnly ? null : $this->wrapHtml($html->output);
        $wrappedText = $this->wrapText($text->output);

        return new ComposedEmail(
            subject: Str::limit(trim((string) preg_replace('/\s+/', ' ', $renderedSubject->output)), 250, ''),
            html: $wrappedHtml,
            text: $wrappedText,
            storedHtml: $hasRedactions && ! $plainTextOnly ? $this->wrapHtml($this->engine->render($body, $redactedContext, true, $options)->output) : $wrappedHtml,
            storedText: $hasRedactions ? $this->wrapText($this->engine->render($body, $redactedContext, false, $options)->output) : $wrappedText,
            hasRedactions: $hasRedactions,
            warnings: array_values(array_unique([...$renderedSubject->warnings, ...$html->warnings])),
        );
    }

    public function defaultLocale(): string
    {
        return (string) $this->settings->get('notifications.default_locale', config('app.locale', 'en'));
    }

    private function wrapHtml(string $markdownBody): string
    {
        return $this->markdown->render('notifications::mail.message', ['body' => $markdownBody])->toHtml();
    }

    private function wrapText(string $plainBody): string
    {
        return trim($this->markdown->renderText('notifications::mail.message', ['body' => $plainBody])->toHtml());
    }
}
