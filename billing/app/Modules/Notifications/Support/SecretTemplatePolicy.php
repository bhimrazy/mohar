<?php

namespace App\Modules\Notifications\Support;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\SampleContext;
use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use LogicException;

/**
 * The single gate for values that grant access by themselves — one-time links, guest ticket links (security review
 * PR #28, B1). Used by template store and update, preview, ad-hoc send, test-send and every render.
 *
 *  1. Sensitivity is declared by type: merge-field resolvers return a SensitiveValue. A SensitiveValue reaches
 *     only templates it names in allowedIn (never custom or ad-hoc mail), and only as the entire href of an <a>
 *     in the rendered HTML (found by parsing it, not by matching Markdown) or on a "Label: url" line the system
 *     writes in the text part; anywhere else it is masked. The account-link fields reach only the fixed system templates in ACTION_LINKS,
 *     and those never print them in editable text. A name check (isSecretName) is kept as a backstop: a secret-looking
 *     field that is not a SensitiveValue is stripped, and fails the test suite so the resolver gets fixed.
 *  2. Secret templates are structure-only: subject, heading, paragraphs and button label are plain text — no
 *     Markdown links or images, no HTML, nothing URL-like, only allow-listed merge fields without filter arguments.
 *     The only account-controlled values allowed are first names, and URL-like text in them is removed. The system
 *     renders the action button and the text-part link line.
 *  3. Account links must be on a configured origin — APP_URL or notifications.auth_link_host: same scheme, host
 *     and port, no user info — never derived from the request or the link itself.
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

    /**
     * The only merge fields a secret template's text may use: the brand, system values, and the recipient's first
     * name. Values an account holder controls (names, company names, addresses) could put a look-alike link beside
     * the real button, so only first names are allowed and they are rendered without URL-like text.
     */
    public const ALLOWED_FIELDS = ['company.name', 'user.first_name', 'staff.first_name', 'auth.expires_at', 'support.email', 'system.today'];

    /** Allowed fields an account holder controls: URL-like text is removed from their values. */
    private const ACCOUNT_CONTROLLED_FIELDS = ['user.first_name', 'staff.first_name'];

    /** Elements dropped from mail that reveals a sensitive link: they could read or send it (CSS selectors, forms). */
    private const UNSAFE_ELEMENTS = 'style, link, base, meta, script, form, iframe, frame, object, embed, svg, math, template, noscript';

    /** Attributes a revealed link keeps (no ping, no event handlers). */
    private const LINK_ATTRIBUTES = ['href', 'class', 'style', 'target', 'rel', 'title'];

    /** Filters a secret template may apply (none take arguments). */
    public const ALLOWED_FILTERS = ['upper', 'lower'];

    private const DOT = '[.。．｡]';

    private const LABEL = '[\p{L}\p{N}](?:[\p{L}\p{N}\-]*[\p{L}\p{N}])?';

    /** @var array<string, bool>|null sample paths holding a SensitiveValue */
    private static ?array $sensitiveSamplePaths = null;

    public static function isSecretTemplate(string $templateKey): bool
    {
        return array_key_exists($templateKey, self::ACTION_LINKS);
    }

    /**
     * Backstop only: a name that looks like it carries a credential. Real classification is by type (SensitiveValue).
     */
    public static function isSecretName(string $path): bool
    {
        return preg_match('/^auth\.[a-z_]*(url|link)$/i', $path) === 1 || preg_match('/token|secret|password|access_?key|magic_?link|login_?link/i', $path) === 1;
    }

    /**
     * Whether a template may not reference this path: a sensitive field (by type, in the sample data every template
     * is checked against) or a secret-looking name.
     */
    public static function isSensitivePath(string $path): bool
    {
        if (self::$sensitiveSamplePaths === null) {
            self::$sensitiveSamplePaths = self::sensitivePaths(app(SampleContext::class)->build(true));
        }

        return isset(self::$sensitiveSamplePaths[$path]) || self::isSecretName($path);
    }

    /**
     * Problems with a template's editable text; empty when it may be saved.
     *
     * @param  array<string, mixed>  $fields  subject, heading, body, action_label
     * @return array<string, list<string>> field => problems
     */
    public static function violations(string $templateKey, array $fields): array
    {
        $engine = new TemplateEngine;
        $secretTemplate = self::isSecretTemplate($templateKey);
        $problems = [];

        foreach ($fields as $field => $text) {
            if (! is_string($text) || trim($text) === '') {
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
                if (self::isSensitivePath($path) && ! self::allowedAsLinkTarget($templateKey, $path, (string) $field, $text)) {
                    $problems[$field][] = __('Access links and tokens can\'t be used here; the system adds the action button itself.');
                } elseif ($secretTemplate && ! in_array($path, self::ALLOWED_FIELDS, true)) {
                    $problems[$field][] = __('This email carries a one-time link, so it can only use these merge fields: :fields.', ['fields' => implode(', ', self::ALLOWED_FIELDS)]);
                }
            }

            if (! $secretTemplate) {
                continue;
            }

            if (self::hasDisallowedFilters($text)) {
                $problems[$field][] = __('This email carries a one-time link, so merge fields can only use the upper and lower filters, without arguments.');
            }

            $plain = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $text);

            if (str_contains($text, '{%')) {
                $problems[$field][] = __('Plain text only: no {% %} blocks.');
            }

            if (preg_match('/!?\[[^\]]*\]\([^)]*\)|<\s*[a-z!\/]/iu', $plain)) {
                $problems[$field][] = __('Plain text only: no links, images or HTML.');
            }

            if (self::containsUrlLike($plain)) {
                $problems[$field][] = __('This email carries a one-time link, so its text can\'t contain web addresses.');
            }
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $problems);
    }

    /**
     * Defence in depth for secret-template text written to the database directly: drop HTML, Markdown link/image
     * syntax, block tags, URL-like text and filter arguments before merge fields are filled in.
     */
    public static function plainCopy(string $source): string
    {
        $source = strip_tags($source);
        $source = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $source);
        $source = (string) preg_replace('/\{%.*?%\}/s', '', $source);
        $parts = preg_split('/(\{\{.*?\}\})/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(
            fn (string $part): string => str_starts_with($part, '{{') ? self::simplePlaceholder($part) : self::withoutUrlLike($part),
            $parts,
        ));
    }

    public static function containsUrlLike(string $text): bool
    {
        return preg_match(self::urlPattern(), $text) === 1;
    }

    /**
     * The context a secret template's text renders against: allow-listed plain fields only.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function textContext(array $context): array
    {
        $allowed = [];

        foreach (self::ALLOWED_FIELDS as $path) {
            $value = data_get($context, $path);
            $value = is_scalar($value) ? $value : null;

            if (is_string($value) && in_array($path, self::ACCOUNT_CONTROLLED_FIELDS, true)) {
                $value = trim((string) preg_replace('/\s+/u', ' ', self::withoutUrlLike($value)));
            }

            data_set($allowed, $path, $value);
        }

        return $allowed;
    }

    /**
     * The context a free-form template renders against. SensitiveValues it may use become opaque placeholders (see
     * placeSensitive()); every other SensitiveValue — and any secret-looking plain value (backstop) — is removed.
     *
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array<string, string>} [context, placeholder => value]
     */
    public static function prepareContext(array $context, ?string $templateKey): array
    {
        $placeholders = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$placeholders, $templateKey): array {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if ($value instanceof SensitiveValue) {
                    if ($templateKey !== null && $value->allowedIn($templateKey)) {
                        $placeholder = 'zzsensitive'.count($placeholders).bin2hex(random_bytes(4)).'zz';
                        $placeholders[$placeholder] = $value->value;
                        $node[$key] = $placeholder;
                    } else {
                        unset($node[$key]);
                    }
                } elseif (is_array($value)) {
                    $node[$key] = $walk($value, $path);
                } elseif (is_string($value) && $value !== '' && self::isSecretName($path)) {
                    if (app()->runningUnitTests()) {
                        throw new LogicException("Merge field [{$path}] looks like a credential but is not a SensitiveValue. Wrap it in the resolver.");
                    }

                    unset($node[$key]); // fail closed in production
                }
            }

            return $node;
        };

        return [$walk($context, ''), $placeholders];
    }

    /**
     * Puts sensitive values into rendered HTML. The HTML is parsed, not matched as text: a placeholder becomes the
     * real value only where it is the entire href of an <a> whose text holds no placeholder. Every other occurrence
     * — another attribute, an image source, part of another URL, text, a comment — is masked. Mail that reveals a
     * value also loses elements that could read or send it (style sheets with attribute selectors, forms), and the
     * link keeps only presentational attributes. $reveal = false masks all (the log copy).
     *
     * @param  array<string, string>  $placeholders  placeholder => value (prepareContext)
     */
    public static function placeSensitiveHtml(string $html, array $placeholders, bool $reveal): string
    {
        return self::placeInHtml($html, $placeholders, $reveal)[0];
    }

    /**
     * Puts sensitive values into the plain-text part. Each Markdown link to a placeholder, [label](placeholder), is
     * replaced by its label, and the link goes on a line of its own that the system writes: "Label: url". Every other
     * occurrence is masked. $toText converts the Markdown to text in between (EmailBody::text).
     *
     * @param  array<string, string>  $placeholders
     * @param  Closure(string): string  $toText
     */
    public static function placeSensitiveText(string $markdown, array $placeholders, bool $reveal, Closure $toText): string
    {
        if ($placeholders === []) {
            return $toText($markdown);
        }

        $any = implode('|', array_map(fn (string $placeholder): string => preg_quote($placeholder, '/'), array_keys($placeholders)));
        $link = '(?<!!)\[([^\[\]\n]*)\]\(\s*('.$any.')\s*\)';
        $tokens = [];
        $lines = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $own = [];
            $whole = preg_match('/^\s*'.$link.'\s*$/', $line) === 1;
            $line = (string) preg_replace_callback('/'.$link.'/', function (array $match) use (&$own, &$tokens, $placeholders): string {
                $label = trim(self::withoutUrlLike(self::mask($match[1], $placeholders)));
                $token = 'zzlink'.count($tokens).bin2hex(random_bytes(6)).'zz';
                $tokens[$token] = $placeholders[$match[2]];
                $own[] = ($label !== '' ? $label : __('Link')).': '.$token;

                return $label;
            }, $line);

            array_push($lines, ...($whole ? $own : [$line, ...$own]));
        }

        $text = self::mask($toText(implode("\n", $lines)), $placeholders);

        foreach ($tokens as $token => $value) {
            $text = str_replace($token, $reveal ? str_replace(["\r", "\n"], '', $value) : '••••', $text);
        }

        return $text;
    }

    /**
     * Masks every placeholder (subjects, labels and anything not placed as a link).
     *
     * @param  array<string, string>  $placeholders
     */
    public static function mask(string $text, array $placeholders): string
    {
        foreach (array_keys($placeholders) as $placeholder) {
            $text = str_ireplace($placeholder, '••••', $text);
        }

        return $text;
    }

    /**
     * The template's one-time link, unwrapped from its SensitiveValue, if it is on a configured origin.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxError when the link is missing or not allowed (the send fails closed)
     */
    public static function actionUrl(string $templateKey, array $context): string
    {
        $value = data_get($context, self::ACTION_LINKS[$templateKey] ?? '');

        if (! $value instanceof SensitiveValue || $value->value === '') {
            throw new TemplateSyntaxError('The one-time link is missing.');
        }

        if (! self::isAllowedLink($value->value)) {
            throw new TemplateSyntaxError('The one-time link is not on an allowed origin (APP_URL or notifications.auth_link_host).');
        }

        return $value->value;
    }

    /**
     * Same scheme, host and port as a configured origin (https only in production), no user info or backslashes.
     */
    public static function isAllowedLink(string $url): bool
    {
        if (preg_match('/[\\\\\s\x00-\x1f]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        if (! ($scheme === 'https' || ($scheme === 'http' && ! app()->isProduction()))) {
            return false;
        }

        $origin = $scheme.'://'.strtolower(rtrim($parts['host'], '.')).':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return in_array($origin, self::allowedOrigins(), true);
    }

    /**
     * Why every account link would be refused, if the configuration makes that so: shown in the security log, the
     * admin dashboard's health card and the install pre-flight. Null when links can be sent.
     */
    public static function accountLinkProblem(): ?string
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return __('APP_URL ":url" has no host, so password-reset, verification and invitation emails are held. Set APP_URL to the public https:// address.', ['url' => $url]);
        }

        if (app()->isProduction() && strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return __('APP_URL is ":url": in production account links must use https://, so password-reset, verification and invitation emails are held. Set APP_URL to the https:// address.', ['url' => $url]);
        }

        return null;
    }

    /**
     * @return list<string> scheme://host:port
     */
    public static function allowedOrigins(): array
    {
        $appUrl = parse_url((string) config('app.url'));
        $scheme = strtolower((string) ($appUrl['scheme'] ?? 'https'));
        $origins = [];

        if (isset($appUrl['host'])) {
            $origins[] = $scheme.'://'.strtolower(rtrim($appUrl['host'], '.')).':'.($appUrl['port'] ?? ($scheme === 'https' ? 443 : 80));
        }

        $extra = app(Settings::class)->get('notifications.auth_link_host');

        foreach (is_string($extra) ? explode(',', $extra) : [] as $entry) {
            $entry = strtolower(trim($entry));

            if (preg_match('/^([a-z0-9.\-]+?)\.?(?::(\d+))?$/', $entry, $match)) {
                $origins[] = 'https://'.$match[1].':'.($match[2] ?? 443);
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, bool>
     */
    private static function sensitivePaths(array $context, string $prefix = ''): array
    {
        $paths = [];

        foreach ($context as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if ($value instanceof SensitiveValue) {
                $paths[$path] = true;
            } elseif (is_array($value)) {
                $paths += self::sensitivePaths($value, $path);
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, string>  $placeholders
     * @return array{0: string, 1: int} [html, occurrences masked]
     */
    private static function placeInHtml(string $html, array $placeholders, bool $reveal): array
    {
        if ($placeholders === [] || self::mask($html, $placeholders) === $html) {
            return [$html, 0];
        }

        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NOERROR);
        $body = $document->body;

        if ($body === null) {
            return [self::mask($html, $placeholders), 1];
        }

        foreach (iterator_to_array($body->querySelectorAll(self::UNSAFE_ELEMENTS)) as $element) {
            $element->remove();
        }

        foreach ($body->querySelectorAll('a[href]') as $link) {
            $href = trim((string) $link->getAttribute('href'));

            if (! isset($placeholders[$href]) || $link->namespaceURI !== 'http://www.w3.org/1999/xhtml' || self::mask((string) $link->textContent, $placeholders) !== $link->textContent) {
                continue;
            }

            self::keepLinkAttributes($link);
            $link->setAttribute('href', $reveal ? $placeholders[$href] : '••••');
        }

        $out = $body->innerHTML;
        $masked = 0;

        foreach (array_keys($placeholders) as $placeholder) {
            $masked += substr_count(strtolower($out), strtolower($placeholder));
        }

        return [self::mask($out, $placeholders), $masked];
    }

    private static function keepLinkAttributes(Element $link): void
    {
        foreach (iterator_to_array($link->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), self::LINK_ATTRIBUTES, true)) {
                $link->removeAttribute($attribute->name);
            }
        }
    }

    /**
     * Free-form templates may use a sensitive field only in their body, only if it is allowed for them, and only
     * where the system will place it: rendered against the sample data, every occurrence must be the entire href
     * of a link (placeInHtml). Secret system templates never print one (the system renders their button).
     */
    private static function allowedAsLinkTarget(string $templateKey, string $path, string $field, string $text): bool
    {
        if (self::isSecretTemplate($templateKey) || $field !== 'body') {
            return false;
        }

        $sample = data_get(app(SampleContext::class)->build(true), $path);

        if (! $sample instanceof SensitiveValue || ! $sample->allowedIn($templateKey)) {
            return false;
        }

        [$context, $placeholders] = self::prepareContext(app(SampleContext::class)->build(true), $templateKey);

        try {
            $markdown = (new TemplateEngine)->render($text, $context, true)->output;
        } catch (TemplateSyntaxError) {
            return false;
        }

        return self::placeInHtml(EmailBody::html($markdown), $placeholders, true)[1] === 0;
    }

    private static function hasDisallowedFilters(string $text): bool
    {
        preg_match_all('/\{\{(.*?)\}\}/s', $text, $placeholders);

        foreach ($placeholders[1] as $inner) {
            foreach (array_slice(array_map('trim', explode('|', $inner)), 1) as $filter) {
                if (! in_array($filter, self::ALLOWED_FILTERS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * {{ path | upper(…) | default("…") }} → {{ path | upper }}: no filter arguments survive.
     */
    private static function simplePlaceholder(string $placeholder): string
    {
        $segments = array_map('trim', explode('|', trim(substr($placeholder, 2, -2))));
        $path = (string) array_shift($segments);

        if (! preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path)) {
            return '';
        }

        $filters = array_values(array_filter($segments, fn (string $filter): bool => in_array($filter, self::ALLOWED_FILTERS, true)));

        return '{{ '.implode(' | ', [$path, ...$filters]).' }}';
    }

    private static function withoutUrlLike(string $text): string
    {
        return (string) preg_replace(self::urlPattern(), '', $text);
    }

    /**
     * Schemes, protocol-relative and www. links, IPv4/IPv6 literals and domain names — including IDN, punycode and
     * full-width dots — i.e. anything a mail client could turn into a link.
     */
    private static function urlPattern(): string
    {
        $dot = self::DOT;
        $label = self::LABEL;

        return '~(?:\b[a-z][a-z0-9+.\-]*:/*\S+)'
            .'|(?://\S+)'
            .'|(?:\bwww'.$dot.'\S+)'
            .'|(?:\b\d{1,3}(?:'.$dot.'\d{1,3}){3}\b\S*)'
            .'|(?:\[[0-9a-f:]{2,}\]\S*)'
            .'|(?:(?<![\p{L}\p{N}\-])'.$label.'(?:'.$dot.$label.')*'.$dot.'(?:\p{L}{2,}|xn--[a-z0-9\-]+)(?![\p{L}\p{N}])\S*)~iu';
    }
}
