<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;

/**
 * The single gate for one-time links (security review PR #28, B1 final design). Used by template store and
 * update, preview, test-send and every render.
 *
 *  1. Secret merge fields are a closed set: only the fixed system templates in ACTION_LINKS receive them, and
 *     even those never reference them in staff-editable text. Every other template is rejected on save if it
 *     references one, and renders it as empty (the field is removed from its context).
 *  2. Secret templates are structure-only: subject, heading, body paragraphs and button label are plain text —
 *     no Markdown links or images, no HTML, nothing that parses as a URL, only allow-listed merge fields. The
 *     system renders the action button and the text-part link line from the secret field.
 *  3. The link host must be one configured here (config('app.url') and notifications.auth_link_host), never
 *     derived from the link being rendered.
 */
final class SecretTemplatePolicy
{
    /** The secret-bearing system templates and the one secret link each carries. */
    public const ACTION_LINKS = [
        'auth.password_reset' => 'auth.reset_url',
        'staff.password_reset' => 'auth.reset_url',
        'auth.email_verification' => 'auth.verify_url',
        'auth.user_invitation' => 'auth.invite_url',
        'staff.invitation' => 'auth.invite_url',
    ];

    /** The only merge fields a secret template's text may use. */
    public const ALLOWED_FIELDS = [
        'company.name', 'recipient.name', 'recipient.first_name', 'recipient.email', 'user.first_name', 'user.email',
        'staff.first_name', 'staff.email', 'client.first_name', 'client.full_name', 'client.company_name',
        'auth.expires_at', 'support.email', 'system.today',
    ];

    /** Anything a mail client could turn into a link. Merge placeholders are removed before this runs. */
    private const URL_PATTERN = '~(?:\b[a-z][a-z0-9+.\-]*:/*\S+)|(?://\S+)|(?:\bwww\.\S+)|(?:\b[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*\.[a-z]{2,}\b\S*)~i';

    public static function isSecretTemplate(string $templateKey): bool
    {
        return array_key_exists($templateKey, self::ACTION_LINKS);
    }

    /**
     * Whether a merge-field path is (or could hold) a secret: the one-time link fields and anything named like a token.
     */
    public static function isSecretPath(string $path): bool
    {
        return preg_match('/^auth\.[a-z_]*url$/', $path) === 1 || preg_match('/token|secret|password/i', $path) === 1;
    }

    /**
     * Problems with a template's editable text; empty when it may be saved. For any template: no secret fields.
     * For secret templates additionally: plain text only, allow-listed fields, a heading and a button label.
     *
     * @param  array{subject?: string|null, heading?: string|null, body?: string|null, action_label?: string|null}  $fields
     * @return array<string, list<string>> field => problems
     */
    public static function violations(string $templateKey, array $fields): array
    {
        $engine = new TemplateEngine;
        $secretTemplate = self::isSecretTemplate($templateKey);
        $problems = [];

        foreach ($fields as $field => $text) {
            if (! is_string($text) || $text === '') {
                if ($secretTemplate && in_array($field, ['heading', 'action_label'], true)) {
                    $problems[$field][] = __('Required.');
                }

                continue;
            }

            try {
                $paths = $engine->validate($text);
            } catch (TemplateSyntaxError) {
                continue; // reported by the syntax check
            }

            foreach ($paths as $path) {
                if (self::isSecretPath($path)) {
                    $problems[$field][] = __('One-time links and tokens can\'t be used in templates; the system adds the action button itself.');
                } elseif ($secretTemplate && ! in_array($path, self::ALLOWED_FIELDS, true)) {
                    $problems[$field][] = __('This email carries a one-time link, so it can only use these merge fields: :fields.', ['fields' => implode(', ', self::ALLOWED_FIELDS)]);
                }
            }

            if (! $secretTemplate) {
                continue;
            }

            $plain = self::withoutPlaceholders($text);

            if (str_contains($text, '{%')) {
                $problems[$field][] = __('Plain text only: no {% %} blocks.');
            }

            if (preg_match('/!?\[[^\]]*\]\([^)]*\)|<\s*[a-z!\/]/i', $plain)) {
                $problems[$field][] = __('Plain text only: no links, images or HTML.');
            }

            if (preg_match(self::URL_PATTERN, $plain)) {
                $problems[$field][] = __('This email carries a one-time link, so its text can\'t contain web addresses.');
            }
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $problems);
    }

    /**
     * Defence in depth for text written to the database directly: drop HTML, Markdown link/image syntax, block
     * tags and anything URL-like from a secret template's source before merge fields are filled in.
     */
    public static function plainCopy(string $source): string
    {
        $source = strip_tags($source);
        $source = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $source);
        $source = (string) preg_replace('/\{%.*?%\}/s', '', $source);

        // Remove URL-like text outside {{ placeholders }}.
        $parts = preg_split('/(\{\{.*?\}\})/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(
            fn (string $part): string => str_starts_with($part, '{{') ? $part : (string) preg_replace(self::URL_PATTERN, '', $part),
            $parts,
        ));
    }

    /**
     * The context a secret template's text renders against: allow-listed fields only.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function textContext(array $context): array
    {
        $allowed = [];

        foreach (self::ALLOWED_FIELDS as $path) {
            data_set($allowed, $path, data_get($context, $path));
        }

        return $allowed;
    }

    /**
     * Any context minus secret fields — what every non-secret template renders against.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function withoutSecrets(array $context, string $prefix = ''): array
    {
        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (self::isSecretPath($path)) {
                unset($context[$key]);
            } elseif (is_array($value)) {
                $context[$key] = self::withoutSecrets($value, $path);
            }
        }

        return $context;
    }

    /**
     * The template's one-time link, if it is on a configured host and uses https (http only in local).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError when the link is missing or points elsewhere (the send fails and AuthMailer falls back)
     */
    public static function actionUrl(string $templateKey, array $context): string
    {
        $url = data_get($context, self::ACTION_LINKS[$templateKey] ?? '');

        if (! is_string($url) || $url === '') {
            throw new TemplateSyntaxError('The one-time link is missing.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($host, self::allowedHosts(), true) || ! ($scheme === 'https' || ($scheme === 'http' && ! app()->isProduction()))) {
            throw new TemplateSyntaxError('The one-time link is not on an allowed host (APP_URL or notifications.auth_link_host).');
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = [(string) parse_url((string) config('app.url'), PHP_URL_HOST)];
        $extra = app(Settings::class)->get('notifications.auth_link_host');

        foreach (is_string($extra) ? explode(',', $extra) : [] as $host) {
            $hosts[] = trim($host);
        }

        return array_values(array_unique(array_filter(array_map(fn (string $host): string => strtolower(rtrim($host, '.')), $hosts))));
    }

    private static function withoutPlaceholders(string $text): string
    {
        return (string) preg_replace('/\{\{.*?\}\}/s', ' ', $text);
    }
}
