<?php

namespace App\Modules\Notifications\Support;

use App\Data\Notifications\SensitiveValue;
use App\Modules\Core\Support\AccountLinks;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\SampleContext;
use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\ProcessingInstruction;
use Dom\XPath;
use DOMDocument;
use DOMNode;
use DOMXPath;
use LogicException;

/**
 * The single gate for values that grant access by themselves — one-time links, guest ticket links (security review
 * PR #28, B1). Used by template store and update, preview, ad-hoc send, test-send and every render.
 *
 *  1. Sensitivity is declared by type: merge-field resolvers return a SensitiveValue. Staff-editable text never
 *     gets one, in any template: templates that reference one are rejected on save, and every SensitiveValue is
 *     removed from the context template text renders against. A name check (isSecretName) is kept as a backstop:
 *     a secret-looking field that is not a SensitiveValue is stripped, and fails the test suite.
 *  2. Only the system places such a link, as its own action button: in the account templates (ACTION_LINKS) and
 *     the client ticket templates (SYSTEM_ACTIONS). For a sensitive link the button goes into a system slot of the
 *     layout after the mail is fully built (Markdown, layout, CSS inlining); staff content in that mail is reduced
 *     to an element and attribute allow-list first, and the finished HTML must hold the value exactly once, as the
 *     href of the system button, read by both the HTML5 parser and libxml — otherwise nothing is sent.
 *  3. Account templates are structure-only: subject, heading, paragraphs and button label are plain text — no
 *     Markdown links or images, no HTML, nothing URL-like, only allow-listed merge fields without filter arguments.
 *     The only account-controlled values allowed are first names, and URL-like text in them is removed.
 *  4. Account links must be on a configured origin — APP_URL or notifications.auth_link_host: same scheme, host
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

    /**
     * Client ticket templates and the link their system button carries ("View your ticket"). The guest link holds
     * the ticket's access key; the logged-in client's link is placed the same way so the templates are one shape.
     */
    public const SYSTEM_ACTIONS = [
        'ticket.autoresponse' => 'ticket.url',
        'ticket.opened_by_staff' => 'ticket.url',
        'ticket.reply' => 'ticket.url',
        'ticket.auto_closed' => 'ticket.url',
    ];

    /**
     * Staff content allowed in mail that carries a sensitive link: element => allowed attributes. Everything else
     * is unwrapped to its text, and the elements in DROPPED_ELEMENTS are removed with their content.
     */
    private const ALLOWED_ELEMENTS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'hr' => [],
        'a' => ['href', 'class', 'target', 'rel', 'style'],
        // The layout's own tables: key facts (EmailBody) and the button markup.
        'table' => ['class', 'align', 'width', 'cellpadding', 'cellspacing', 'role'],
        'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['align', 'class'], 'td' => ['align', 'class', 'style'],
    ];

    private const DROPPED_ELEMENTS = ['script', 'style', 'xmp', 'noembed', 'noframes', 'plaintext', 'textarea', 'title', 'iframe', 'frame', 'frameset', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'select', 'head', 'meta', 'link', 'base', 'form', 'img', 'picture', 'video', 'audio', 'source', 'canvas', 'map', 'area'];

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
     * The merge field whose link a free-form template's system button carries, if it has one.
     */
    public static function systemActionPath(string $templateKey): ?string
    {
        return self::SYSTEM_ACTIONS[$templateKey] ?? null;
    }

    /**
     * Where the system button goes until the mail is built: random per render (128 bits), so no template text can
     * contain or anticipate it.
     */
    public static function actionToken(): string
    {
        return 'zzaction'.bin2hex(random_bytes(16)).'zz';
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
        return self::isSensitiveSamplePath($path) || self::isSecretName($path);
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
        $problems = [];

        foreach ($fields as $field => $text) {
            // Account templates are plain text throughout; a ticket template's button label is plain text too.
            $secretTemplate = self::isSecretTemplate($templateKey) || ($field === 'action_label' && self::systemActionPath($templateKey) !== null);

            if (! is_string($text) || trim($text) === '') {
                if (self::isSecretTemplate($templateKey) && in_array($field, ['heading', 'action_label'], true)) {
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
                if (self::isSensitivePath($path)) {
                    $problems[$field][] = __('Access links and tokens can\'t be used in template text; the system adds the button for them itself.');
                } elseif ($secretTemplate && ! in_array($path, self::ALLOWED_FIELDS, true)) {
                    $problems[$field][] = __('Plain text only: it can use these merge fields: :fields.', ['fields' => implode(', ', self::ALLOWED_FIELDS)]);
                }
            }

            if (! $secretTemplate) {
                continue;
            }

            if (self::hasDisallowedFilters($text)) {
                $problems[$field][] = __('Plain text only: merge fields can only use the upper and lower filters, without arguments.');
            }

            $plain = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $text);

            if (str_contains($text, '{%')) {
                $problems[$field][] = __('Plain text only: no {% %} blocks.');
            }

            if (preg_match('/!?\[[^\]]*\]\([^)]*\)|<\s*[a-z!\/]/iu', $plain)) {
                $problems[$field][] = __('Plain text only: no links, images or HTML.');
            }

            if (self::containsUrlLike($plain)) {
                $problems[$field][] = __('Plain text only: no web addresses.');
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
     * Single-line values a client, guest or account holder types (ticket subjects, names, company names). Mail
     * clients turn web addresses in them into links, in the HTML and the text part, so URL-like text is defanged.
     */
    public const UNTRUSTED_TEXT_FIELDS = [
        'ticket.subject', 'ticket.requester_name', 'reply.author_name', 'client.first_name', 'client.last_name',
        'client.full_name', 'client.company_name', 'recipient.name', 'recipient.first_name', 'user.first_name',
        'notice.account', 'notice.actor', 'cancellation.reason',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function neutraliseUntrusted(array $context): array
    {
        foreach (self::UNTRUSTED_TEXT_FIELDS as $path) {
            $value = data_get($context, $path);

            if (is_string($value) && $value !== '') {
                data_set($context, $path, self::defang($value));
            }
        }

        return $context;
    }

    /**
     * "evil.test/login" → "evil[.]test/login", "https://x" → "https[:]//x": readable, but no mail client links it.
     */
    public static function defang(string $text): string
    {
        return (string) preg_replace_callback(self::urlPattern(), fn (array $match): string => str_replace(['://', '.', '。', '．', '｡'], ['[:]//', '[.]', '[.]', '[.]', '[.]'], $match[0]), $text);
    }

    /**
     * The context staff-editable text renders against: every SensitiveValue removed, and every field that is
     * sensitive in the sample data (e.g. the ticket link, whether or not this ticket's link is a guest link). A
     * secret-looking plain value is a resolver bug (backstop): it fails the test suite and is removed in production.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function prepareContext(array $context): array
    {
        $walk = function (array $node, string $prefix) use (&$walk): array {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if ($value instanceof SensitiveValue || (is_string($value) && self::isSensitiveSamplePath($path))) {
                    unset($node[$key]);
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

        return $walk($context, '');
    }

    /**
     * Staff content for mail that carries a sensitive link, reduced to ALLOWED_ELEMENTS: unknown elements are
     * unwrapped to their text, raw-text and embedding elements are dropped with their content, links keep an href
     * only to a configured origin, styles that load anything are dropped, and blank lines are removed so the
     * layout's Markdown pass keeps the result as one HTML block. Parsed and serialised once by the HTML5 parser,
     * so what follows is plain, balanced, escaped markup that every later parser reads the same way. Any word in a
     * text node or attribute value that holds one of the $sensitive secrets (e.g. the guest's own link pasted into a
     * reply) becomes •••• (maskValues, the shared normaliser), so only the system button carries it.
     *
     * @param  list<string>  $sensitive
     */
    public static function sanitizeStaffHtml(string $html, array $sensitive = []): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NOERROR);
        $body = $document->body;

        if ($body === null) {
            return '';
        }

        self::sanitizeChildren($body);
        $sensitive = array_values(array_filter($sensitive, fn (string $value): bool => $value !== ''));

        if ($sensitive !== []) {
            foreach ((new XPath($document))->query('//text()', $body) as $node) {
                $node->textContent = self::maskValues((string) $node->textContent, $sensitive);
            }

            foreach ($body->getElementsByTagName('*') as $element) {
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    $attribute->value = self::maskValues($attribute->value, $sensitive);
                }
            }
        }

        return trim((string) preg_replace('/\R\s*\R/', "\n", $body->innerHTML));
    }

    /**
     * The one normaliser for finding a secret, shared by masking, counting and the final check on every path (HTML
     * and text): HTML entities and percent-encoding decoded until nothing changes (so partial and double encodings
     * are caught), then lower case.
     */
    public static function normalise(string $text): string
    {
        for ($pass = 0; $pass < 6; $pass++) {
            $decoded = rawurldecode(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($decoded === $text) {
                break;
            }

            $text = $decoded;
        }

        return mb_strtolower($text);
    }

    /**
     * Replaces every word (a run without whitespace) whose normalised form holds a secret — the access key or token
     * itself, so any link or encoding that carries it is caught — with ••••. For plain text and single DOM values
     * (text nodes, attribute values), never for markup.
     *
     * @param  list<string>  $secrets
     */
    public static function maskValues(string $text, array $secrets): string
    {
        $needles = array_values(array_filter(array_map(self::normalise(...), $secrets), fn (string $needle): bool => $needle !== ''));

        if ($needles === []) {
            return $text;
        }

        return (string) preg_replace_callback('/\S+/u', function (array $word) use ($needles): string {
            $normalised = self::normalise($word[0]);

            foreach ($needles as $needle) {
                if (str_contains($normalised, $needle)) {
                    return '••••';
                }
            }

            return $word[0];
        }, $text);
    }

    /**
     * How often the secret occurs in $text once normalised (any case, entity or percent encoding).
     */
    public static function secretCount(string $text, string $secret): int
    {
        $needle = self::normalise($secret);

        return $needle === '' ? 0 : substr_count(self::normalise($text), $needle);
    }

    /**
     * Whether the finished mail holds the secret exactly once, in the href of the system button (an <a> with the
     * "button" class whose href is exactly $url) — read by the HTML5 parser and by libxml (which the CSS inliner and
     * many mail tools use), so a difference between parsers can't hide a second copy. Attribute and text values
     * are compared decoded (entities, then percent-encoding) and case-insensitively.
     */
    public static function placedOnlyAsSystemButton(string $html, string $url, ?string $secret = null): bool
    {
        $secret = $secret !== null && $secret !== '' ? $secret : $url;

        if ($url === '' || self::secretCount($html, $secret) !== 1) {
            return false;
        }

        $html5 = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $libxml = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $libxml->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return false;
        }

        $holds = fn (string $value): bool => self::secretCount($value, $secret) > 0;
        $parsers = [
            [$html5->getElementsByTagName('*'), (new XPath($html5))->query('//text() | //comment()')],
            [$libxml->getElementsByTagName('*'), (new DOMXPath($libxml))->query('//text() | //comment()')],
        ];

        foreach ($parsers as [$elements, $texts]) {
            $hits = 0;

            foreach ($elements as $element) {
                foreach ($element->attributes ?? [] as $attribute) {
                    if (! $holds((string) $attribute->value)) {
                        continue;
                    }

                    $isButton = strtolower($element->localName ?? '') === 'a' && strtolower($attribute->name) === 'href'
                        && trim((string) $attribute->value) === $url
                        && in_array('button', preg_split('/\s+/', (string) $element->getAttribute('class')) ?: [], true);

                    if (! $isButton) {
                        return false;
                    }

                    $hits++;
                }
            }

            foreach ($texts === false ? [] : $texts as $node) {
                if (($node instanceof Node || $node instanceof DOMNode) && $holds((string) $node->textContent)) {
                    return false;
                }
            }

            if ($hits !== 1) {
                return false;
            }
        }

        return true;
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
     * The part of the template's one-time link that grants access (the token or signature), for the final check.
     *
     * @param  array<string, mixed>  $context
     */
    public static function actionSecret(string $templateKey, array $context): string
    {
        $value = data_get($context, self::ACTION_LINKS[$templateKey] ?? '');

        return $value instanceof SensitiveValue ? $value->secret() : '';
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
        return AccountLinks::problem();
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

    private static function sanitizeChildren(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof Comment || $node instanceof ProcessingInstruction) {
                $node->remove();

                continue;
            }

            if (! $node instanceof Element) {
                continue;
            }

            $name = strtolower($node->localName);

            if (in_array($name, self::DROPPED_ELEMENTS, true) || $node->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
                $node->remove();

                continue;
            }

            self::sanitizeChildren($node);

            if (! array_key_exists($name, self::ALLOWED_ELEMENTS)) {
                $node->replaceWith(...iterator_to_array($node->childNodes));

                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $attributeName = strtolower($attribute->name);
                $keep = in_array($attributeName, self::ALLOWED_ELEMENTS[$name], true)
                    && ! ($attributeName === 'href' && ! self::isAllowedLink(trim($attribute->value)))
                    && ! ($attributeName === 'style' && preg_match('/url\s*\(|expression|@import|\\|behaviou?r|binding/i', $attribute->value) === 1);

                if (! $keep) {
                    $node->removeAttribute($attribute->name);
                }
            }
        }
    }

    private static function isSensitiveSamplePath(string $path): bool
    {
        if (self::$sensitiveSamplePaths === null) {
            self::$sensitiveSamplePaths = self::sensitivePaths(app(SampleContext::class)->build(true));
        }

        return isset(self::$sensitiveSamplePaths[$path]);
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
