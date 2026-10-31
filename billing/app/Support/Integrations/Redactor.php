<?php

namespace App\Support\Integrations;

use App\Contracts\Integrations\DeclaresSensitiveKeys;
use Closure;
use Error;
use Exception;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use ReflectionProperty;
use Throwable;

/**
 * Removes secrets from integration logs and from messages shown to staff. All rules live here; the host redacts what
 * drivers return, and drivers never call this.
 *
 * - Known values (credentials, request secrets) come as a RedactionSet, or as a list it is built from; see RedactionSet
 *   for the forms they are matched in. A key or a number that holds one is withheld whole.
 * - redact(): module and registrar command logs. A key is sensitive when one of its words (`api_key`, `api-key`,
 *   `ApiKey` and `APIKey` all read as "api key"), a pair of adjacent words, or a run-together spelling (`eppcode`,
 *   `accesshash`) names a secret; a sensitive key hides its whole value. Matching whole words keeps `author`,
 *   `country_code` and `keyword` visible. Inside strings, known credential values, cPanel `cpsess…` tokens, provider
 *   key shapes (Stripe `sk_`, `rk_`, `whsec_`, client secrets; Khalti secret keys), URL userinfo, secret
 *   `name=value`, `name: value` and `"name":"value"` pairs, XML elements and attributes (`<password>`,
 *   `<item key="reg_password">`, CDATA), and whole JSON documents are masked wherever they appear. Text is read in one
 *   left-to-right pass, so the cost is linear in its length.
 * - redactPayment(): payment provider payloads (gateway log, transaction and refund `raw`). Every leaf under a
 *   card, number, token, secret, signature, password or expiry key is masked, null included, while nested objects are
 *   walked so a card's brand and last 4 digits stay visible. Text leaves get the same string rules as redact(), and
 *   Luhn-valid card numbers in text are masked.
 *
 * @api
 */
final class Redactor
{
    public const MASK = '[redacted]';

    public const CARD_MASK = '[redacted-pan]';

    /** Longest raw driver output kept for staff and logs, in bytes. */
    public const EXCERPT_BYTES = 4096;

    /** Nested values deeper than this are masked rather than walked (self-referencing object graphs). */
    private const MAX_DEPTH = 32;

    /**
     * Values one call reads through objects (each object, and every entry under one); past it they are masked. An
     * object that fans out into new copies of itself stays under the depth cap and would otherwise grow without bound
     * (#399). Plain arrays and decoded JSON text are bounded by the input, so they are not counted.
     */
    private const MAX_NODES = 50_000;

    /** Exceptions behind the first one whose messages maskThrowable() masks; those further down are withheld. */
    private const MAX_PREVIOUS = 16;

    /** A key is sensitive when it is exactly one of these. */
    private const SENSITIVE_KEYS = ['key', 'pw', 'pwd', 'dv', 'phpsessid'];

    /** ...or when one of its words is one of these. */
    private const SENSITIVE_WORDS = [
        'password', 'passwd', 'pass', 'passphrase', 'secret', 'token', 'auth', 'authorization', 'signature', 'hash',
        'hmac', 'session', 'sessions', 'cookie', 'cookies', 'credential', 'credentials', 'epp', 'otp', 'apikey', 'cvc',
        'cvv', 'cvv2', 'pan', 'dv', 'pw',
    ];

    /** ...or when two adjacent words are one of these pairs. */
    private const SENSITIVE_PAIRS = [
        ['api', 'key'], ['private', 'key'], ['access', 'key'], ['secret', 'key'], ['license', 'key'], ['licence', 'key'],
        ['ssh', 'key'], ['signing', 'key'], ['encryption', 'key'], ['auth', 'key'], ['transfer', 'code'],
        ['auth', 'code'], ['auth', 'info'], ['card', 'number'], ['account', 'number'], ['data', 'validation'],
    ];

    /** ...or when the key, lowercased and with separators removed, contains one of these run-together spellings. */
    private const RUN_TOGETHER = '/password|passwd|secret|token|apikey|signature|authorization|credential|eppcode|authcode|authinfo|authkey|accesshash|accesskey|privatekey|secretkey|hash|datavalidation|session/';

    /** Payment payload keys (develop's gateway rule, plus `authorization`, `dv` and `dataValidation`, payments-nepal.md §4). */
    private const PAYMENT_KEYS = '/card|pan|number|cvc|cvv|token|secret|signature|password|exp|authorization|datavalidation|^dv$/i';

    /** Payment keys that look sensitive but are references staff need. */
    private const PAYMENT_REFERENCES = ['txn_id', 'parent_txn_id'];

    /** Keys that read like a secret by word but are account references support needs (ResellerClub `auth-userid`). */
    private const REFERENCE_KEYS = ['authuserid'];

    /**
     * Values that are secret wherever they appear, whatever the key: cPanel session tokens, Stripe secret and restricted
     * keys and webhook secrets, Khalti secret keys, and bearer tokens outside a labelled header.
     */
    private const SENSITIVE_VALUE_PATTERNS = [
        '/cpsess\d+/',
        '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}+/',
        '/\bwhsec_[A-Za-z0-9]{8,}+/',
        '/\b(?:live|test)_secret_key_[A-Za-z0-9]{8,}+/',
        '/(?<=\b[Bb]earer )[A-Za-z0-9\-._~+\/]{16,}+=*+/',
    ];

    /** Stripe client secrets (`pi_…_secret_…`): the object id before `_secret_` stays readable. */
    private const CLIENT_SECRET_PATTERN = '/\b((?:pi|seti|src)_[A-Za-z0-9]{8,}+_secret_)[A-Za-z0-9]{8,}+/';

    /** URL userinfo (`https://user:pass@host`, up to the last `@` before the path): the whole userinfo is masked, scheme and host stay. */
    private const URL_USERINFO_PATTERN = '/\b([A-Za-z][A-Za-z0-9+.\-]{0,15}+:\/\/)[^\s\/?#"\'<>]*@/';

    /** A `name=value` pair inside a percent-encoded URL (a login link passed as another parameter's value). */
    private const ENCODED_PAIR_PATTERN = '/((?:%3F|%26|%3B)([A-Za-z][A-Za-z0-9_.\-]{0,63}+)%3D)((?:(?!%26)[^&\s"\'#])++)/i';

    /**
     * One token of the text pass: an XML start or end tag (1 slash, 2 qualified name, 3 local name, 4 attributes), or a
     * pair's name (5) and separator (6): `name=`, `name: `, `"name":`, `\"name\":`, `'name' =>`, print_r's
     * `[name] =>`, HTML-escaped `&quot;name&quot;:`; the value may start on the next line (pretty-printed JSON). Only the name and
     * separator are consumed, so the value is read by valueEnd() when the name is secret and scanned again when not.
     * Every repeat is possessive or bounded, so no input backtracks more than a constant per position. Tag names are
     * ASCII here on purpose, unlike HtmlDocumentMarker::nameLength(): a tag's attribute text is read for `name=value`
     * only, so a non-ASCII `<é api-key: …>` must stay text for the pair rule to see its value.
     */
    private const TOKEN_PATTERN = '/<(\/?)((?:[A-Za-z_][\w.\-]*+:)?([A-Za-z_][\w.\-]*+))([^<>]*+)>|(?<![A-Za-z0-9_.])'.self::PAIR.'/';

    /** A pair's name and separator: 1 name, 2 separator. */
    private const NAME_SEPARATOR = '([A-Za-z][A-Za-z0-9_.\-\[\]]{0,254}+)((?:\\\\?["\']|&quot;)?[ \t]{0,16}+(?:=>?|:)\s*+)';

    /** NAME_SEPARATOR as TOKEN_PATTERN reads it (groups 5 and 6 there): not before `//`, so `https://` is no pair. */
    private const PAIR = self::NAME_SEPARATOR.'(?!\/\/)';

    /**
     * NAME_SEPARATOR at a given offset, for a pair that ends a masked span (trailingPair). It takes a value starting with
     * `//` (`pw=ab;token: //x`), as the tail rule did before #462: only a scan-level pair excludes URL schemes.
     */
    private const TAIL_PAIR_AT = '/\G'.self::NAME_SEPARATOR.'/';

    /** An authorization scheme in front of a credential (`Authorization: Key …` is Khalti, `whm`/`cpanel` are WHM). */
    private const AUTH_SCHEME_PATTERN = '/\G(?:Bearer|Basic|Digest|Token|Key|whm|cpanel|OAuth|HMAC)[ \t]++/i';

    /** XML attributes: 1 name, 2 separator, 3 value. */
    private const ATTRIBUTE_PATTERN = '/([A-Za-z_:][\w:.\-]*+)([ \t\r\n]*+=[ \t\r\n]*+)("[^"]*+"|\'[^\']*+\'|[^\s"\'>]++)/';

    /** An `&` ends an unquoted value when the next pair, an entity, a space or the end follows (eSewa secrets hold `&`). */
    private const AMPERSAND_END = '/\G&(?:amp;|#|[A-Za-z_][A-Za-z0-9_.\-\[\]%]{0,254}+=|\s|$)/';

    /** Labels that can introduce a reference in free text (`Session: cs_live_… expired`) as well as a credential. */
    private const REFERENCE_LABELS = ['session', 'hash'];

    /**
     * After a REFERENCE_LABELS label, the only values left readable: Stripe object ids (Checkout Session, PaymentIntent,
     * charge, event, customer, invoice, subscription, refund, payment, SetupIntent), and only when the id is the whole
     * value (a UUID is a session-token shape, so it is masked). Anything else after the label is masked.
     */
    private const REFERENCE_ID_PATTERN = '/\G(?:cs|pi|ch|evt|cus|in|sub|re|py|seti)_(?:live_|test_)?[A-Za-z0-9]{4,}+/';

    /**
     * HTML void elements: no end tag, so a secret `name` on one (`<input name="password">`) masks only the text before
     * the next tag (and a CDATA section), which in HTML is usually nothing. XML uses the same names with content
     * (`<param name="password">…</param>`), and that content is masked by the same rule. A prefixed name is never HTML.
     * An element with no end tag keeps that next-tag bound only in an HTML document (HtmlDocumentMarker finds a
     * `<!doctype html` or `<html` start tag at markup level before it); anywhere else it is an XML container and a child element after it fails closed (voidContainerEnd).
     */
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Header names whose whole line is secret: every cookie on it (`Cookie: theme=dark; cpsession=…`). */
    private const WHOLE_LINE_KEYS = ['cookie', 'set-cookie'];

    /** `curl -u user:password` and the proxy form: the credentials after the flag, quoted or attached (`-uroot:pw`). */
    private const CLI_CREDENTIAL_PATTERN = '/(?<![^\s])((?:-u|-U|--user|--proxy-user)(?:[ \t]++|=)?)(?:\'[^\']*+\'|"[^"]*+"|[^\s"\':]*+:[^\s"\']++)/';

    /** Attributes that name the element's field (OpenSRS `<item key="…">`, `<param name="…">`) rather than hold a value. */
    private const LABEL_ATTRIBUTES = ['key', 'name'];

    /** Attributes that hold the value a label attribute names (`<input name="…" value="…">`, `<meta name="…" content="…">`). */
    private const VALUE_ATTRIBUTES = ['value', 'content'];

    /** An unquoted value ends at one of these, or at an `&` that starts the next pair (see AMPERSAND_END)... */
    private const VALUE_HARD_END = " \t\r\n\f\v\"<>";

    /** ...or at one of these when it is followed by a space, a quote, another end character or the end of the text. */
    private const VALUE_SOFT_END = ',;)]}\'';

    /**
     * innerPairsEnd() reads quoted and bracketed inner values for up to this many times the span's length (plus the
     * floor) before it masks the rest of the text instead (fail closed).
     */
    private const INNER_WORK = 4;

    private const INNER_WORK_FLOOR = 65536;

    /** Names remembered by isSensitiveKey() before the cache starts over. */
    private const KEY_CACHE_SIZE = 2048;

    /** @var array<string, bool> */
    private array $keyCache = [];

    /** Set while scan() runs: the caches below hold offsets into the text it scans and are cleared around it. */
    private bool $scanning = false;

    /** @var array{int, int}|null the last unquoted value valueEnd() read, [start, end) */
    private ?array $unquotedRun = null;

    /** @var array{int, int}|null the last whole-line value pairValueEnd() read, [start, end) */
    private ?array $lineRun = null;

    /** @var array{int, int|false}|null where cdataEnd() last searched for `]]>`, and what it found */
    private ?array $cdataClose = null;

    /** @var array{int, int|false}|null where maskBoundEnd() last searched for `<![CDATA[`, and what it found */
    private ?array $cdataOpen = null;

    /** @var array{int, int|false}|null where maskBoundEnd() last searched for a mask a value end follows, and what it found */
    private ?array $stoppedMask = null;

    private static int $nodesLeft = 0;

    /**
     * Public calls in progress, on any instance, so a call made from inside an unwrap (this instance or a new
     * `app(Redactor::class)`) shares the outer call's budget. Back to 0 when the outermost call returns or throws.
     */
    private static int $calls = 0;

    /**
     * A sensitive key hides its whole value, nested arrays included. Null and empty values are kept, so the log
     * still shows that nothing was sent. Objects are read as arrays first (Arrayable, JsonSerializable, otherwise
     * their public properties), so a DTO in a payload cannot carry a secret past the mask.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  RedactionSet|list<string>  $secrets  values to mask wherever they appear
     * @param  list<string>  $sensitiveKeys  extra exact keys that are secret in this payload only, e.g. `url` in an SSO
     *                                       answer or `code` in an EPP code answer
     * @return array<array-key, mixed>
     */
    public function redact(array $payload, RedactionSet|array $secrets = [], array $sensitiveKeys = []): array
    {
        return $this->budgeted(fn (): array => $this->redactWith($payload, self::set($secrets), array_map('strtolower', $sensitiveKeys), 0));
    }

    /**
     * The extra keys a driver declares secret in one operation's payloads (DeclaresSensitiveKeys, ADR 0023 §5), for
     * the host to pass to redact() and valuesUnder(); [] for a driver that declares none.
     *
     * @return list<string>
     */
    public static function declaredKeys(?object $driver, string $operation): array
    {
        return $driver instanceof DeclaresSensitiveKeys ? array_values(array_filter($driver->sensitiveKeys($operation), is_string(...))) : [];
    }

    /**
     * The text of every scalar under one of $sensitiveKeys (exact, any case), nested arrays included, so the host can
     * also mask those values by value in text the driver composed: a message or error that echoes them.
     *
     * @param  list<string>  $sensitiveKeys
     * @param  array<array-key, mixed>  ...$payloads
     * @return list<string>
     */
    public function valuesUnder(array $sensitiveKeys, array ...$payloads): array
    {
        if ($sensitiveKeys === []) {
            return [];
        }

        $keys = array_map('strtolower', $sensitiveKeys);
        $values = [];
        $nodes = 0;
        $walk = function (array $payload, bool $under, int $depth) use (&$walk, &$values, &$nodes, $keys): void {
            foreach ($payload as $key => $value) {
                if (++$nodes > self::MAX_NODES) {
                    return;
                }

                $hit = $under || (is_string($key) && in_array(strtolower($key), $keys, true));

                if (is_array($value) && $depth < self::MAX_DEPTH) {
                    $walk($value, $hit, $depth + 1);
                } elseif ($hit && (is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '') {
                    $values[] = (string) $value;
                }
            }
        };

        foreach ($payloads as $payload) {
            $walk($payload, false, 0);
        }

        return array_values(array_unique($values));
    }

    /**
     * Payment provider payloads: mask every leaf under a payment-sensitive key (null included), walk nested arrays and
     * objects, and mask Luhn-valid card numbers inside text. $secrets (the gateway's configured credentials) are
     * masked by value in text and numbers.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  RedactionSet|list<string>  $secrets
     * @return array<array-key, mixed>
     */
    public function redactPayment(array $payload, int $depth = 0, RedactionSet|array $secrets = []): array
    {
        return $this->budgeted(fn (): array => $this->redactPaymentWith($payload, $depth, self::set($secrets)));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redactPaymentWith(array $payload, int $depth, RedactionSet $set, bool $counted = false): array
    {
        foreach ($payload as $key => $value) {
            $fromObject = $counted || is_object($value);

            if ($fromObject && ! $this->takeNode()) {
                $payload[$key] = self::MASK;

                continue;
            }

            $value = $this->unwrap($value, $depth);

            if ($depth >= self::MAX_DEPTH && (is_array($value) || is_object($value))) {
                $payload[$key] = self::MASK;
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPaymentWith($value, $depth + 1, $set, $fromObject);
            } elseif (is_string($key) && ! in_array($key, self::PAYMENT_REFERENCES, true) && preg_match(self::PAYMENT_KEYS, $key) === 1) {
                $payload[$key] = self::MASK;
            } else {
                $payload[$key] = match (true) {
                    is_string($value) => $this->scrubCardNumbers($this->maskString($value, $set, [], $depth)),
                    is_int($value), is_float($value) => $set->matches((string) $value) ? self::MASK : $value,
                    default => $value,
                };
            }
        }

        return $payload;
    }

    /**
     * @param  RedactionSet|list<string>  $secrets
     */
    public function maskValues(string $value, RedactionSet|array $secrets): string
    {
        return $this->budgeted(fn (): string => $this->maskString($value, self::set($secrets), [], 0));
    }

    /**
     * Masks, in place, the message of $exception and of up to 16 exceptions behind it, with the shape rules, the set's
     * values and the sets of RedactionScope (calls in progress, and those attached to the chain); any exception further
     * down the chain has its message withheld whole (fail closed). The class, code, trace, flags and `previous` are
     * kept, so the caller reports the original.
     *
     * Only messages are masked. Properties, what `context()` returns (Laravel merges it into the log record) and trace
     * arguments (printed when `zend.exception_ignore_args` is off) are not: production keeps that setting on, and the
     * log processor of ADR 0023 step 4 covers the rest.
     *
     * @template T of Throwable
     *
     * @param  T  $exception
     * @param  RedactionSet|list<string>|null  $secrets
     * @return T
     */
    public function maskThrowable(Throwable $exception, RedactionSet|array|null $secrets = null): Throwable
    {
        $set = RedactionSet::merge(self::set($secrets ?? []), RedactionScope::current($exception));
        $seen = new \SplObjectStorage;

        for ($link = $exception, $hops = 0; $link !== null && ! $seen->contains($link); $link = $link->getPrevious(), $hops++) {
            $seen->attach($link);
            $message = $link->getMessage();
            $masked = $hops <= self::MAX_PREVIOUS ? $this->budgeted(fn (): string => $this->maskString($message, $set, [], 0)) : self::MASK;

            if ($masked !== $message) {
                (new ReflectionProperty($link instanceof Exception ? Exception::class : Error::class, 'message'))->setValue($link, $masked);
            }
        }

        return $exception;
    }

    /**
     * Raw driver output (a provider's response body or error text) made safe to show staff: JSON is redacted by key,
     * any text has known secrets masked, and the result is cut to $limit bytes.
     *
     * @param  RedactionSet|list<string>  $secrets
     */
    public function excerpt(string $raw, RedactionSet|array $secrets = [], int $limit = self::EXCERPT_BYTES): string
    {
        $safe = $this->maskValues($raw, $secrets);

        return strlen($safe) <= $limit ? $safe : mb_strcut($safe, 0, max(0, $limit - 3)).'...';
    }

    /**
     * @param  list<string>  $sensitiveKeys  extra exact keys, lowercase
     */
    public function isSensitiveKey(int|string $key, array $sensitiveKeys = []): bool
    {
        if (is_int($key)) {
            return false;
        }

        $lower = strtolower($key);

        if ($sensitiveKeys !== [] && in_array($lower, $sensitiveKeys, true)) {
            return true;
        }

        if (isset($this->keyCache[$key])) {
            return $this->keyCache[$key];
        }

        // Text supplies the names, so a hostile answer full of distinct names must not grow the cache without bound.
        if (count($this->keyCache) >= self::KEY_CACHE_SIZE) {
            $this->keyCache = [];
        }

        return $this->keyCache[$key] = $this->matchesSecretName($key, $lower);
    }

    /**
     * Mask card-number-like digit runs (13 to 19 digits, Luhn-valid) inside free text such as provider messages.
     */
    public function scrubCardNumbers(string $value): string
    {
        return (string) preg_replace_callback('/\b(?:\d[ -]?){12,18}\d\b/', function (array $match): string {
            $digits = (string) preg_replace('/\D/', '', $match[0]);

            return $this->luhn($digits) ? self::CARD_MASK : $match[0];
        }, $value);
    }

    private function matchesSecretName(string $key, string $lower): bool
    {
        if (in_array($lower, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        $words = $this->words($key);

        if (in_array(implode('', $words), self::REFERENCE_KEYS, true)) {
            return false;
        }

        if (array_intersect($words, self::SENSITIVE_WORDS) !== []) {
            return true;
        }

        for ($index = 1; $index < count($words); $index++) {
            if (in_array([$words[$index - 1], $words[$index]], self::SENSITIVE_PAIRS, true)) {
                return true;
            }
        }

        return preg_match(self::RUN_TOGETHER, implode('', $words)) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $sensitiveKeys
     * @return array<array-key, mixed>
     */
    private function redactWith(array $payload, RedactionSet $set, array $sensitiveKeys, int $depth, bool $counted = false): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $fromObject = $counted || is_object($value);
            $spent = $fromObject && ! $this->takeNode();
            $value = $spent ? self::MASK : $this->unwrap($value, $depth);

            $masked = match (true) {
                $spent => self::MASK,
                $value !== null && $value !== '' && $value !== [] && $this->isSensitiveKey($key, $sensitiveKeys) => self::MASK,
                is_array($value) => $depth >= self::MAX_DEPTH ? self::MASK : $this->redactWith($value, $set, $sensitiveKeys, $depth + 1, $fromObject),
                is_string($value) => $this->maskString($value, $set, $sensitiveKeys, $depth),
                is_int($value), is_float($value) => $set->matches((string) $value) ? self::MASK : $value,
                default => $value,
            };

            // A key that holds a value is renamed and its value withheld (`[redacted]`, `[redacted] 2`, ...).
            if ($set->matches((string) $key)) {
                $masked = self::MASK;

                for ($name = self::MASK, $n = 2; array_key_exists($name, $redacted) || array_key_exists($name, $payload); $n++) {
                    $name = self::MASK.' '.$n;
                }

                $key = $name;
            }

            $redacted[$key] = $masked;
        }

        return $redacted;
    }

    /**
     * An object read as a value. Each unwrap counts towards the depth cap, so an object that returns itself (or a new
     * object each time) ends masked at the cap.
     */
    private function unwrap(mixed $value, int $depth): mixed
    {
        for (; is_object($value); $depth++) {
            if ($depth >= self::MAX_DEPTH) {
                return self::MASK;
            }

            $value = match (true) {
                $value instanceof Arrayable => $value->toArray(),
                $value instanceof JsonSerializable => $value->jsonSerialize(),
                $value instanceof \UnitEnum => $value instanceof \BackedEnum ? $value->value : $value->name,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                default => get_object_vars($value),
            };
        }

        return $value;
    }

    /**
     * Runs one public call under the node budget, which only the outermost call sets.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function budgeted(Closure $call): mixed
    {
        if (self::$calls++ === 0) {
            self::$nodesLeft = self::MAX_NODES;
        }

        try {
            return $call();
        } finally {
            self::$calls--;
        }
    }

    /** Counts one value against the budget; false once it is spent. */
    private function takeNode(): bool
    {
        return self::$nodesLeft-- > 0;
    }

    /**
     * @param  RedactionSet|list<string>  $secrets
     */
    private static function set(RedactionSet|array $secrets): RedactionSet
    {
        return $secrets instanceof RedactionSet ? $secrets : RedactionSet::of($secrets);
    }

    /**
     * Known secret values, provider key shapes, URL userinfo, then one pass over `name=value` pairs (query strings,
     * form bodies, headers, JSON or PHP text after a label) and XML. A string that is a whole JSON document is decoded
     * and redacted by key instead.
     *
     * @param  list<string>  $sensitiveKeys
     */
    private function maskString(string $value, RedactionSet $set, array $sensitiveKeys, int $depth): string
    {
        $trimmed = ltrim($value);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && $depth < self::MAX_DEPTH) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $redacted = $this->redactWith($decoded, $set, $sensitiveKeys, $depth + 1);

                // Nothing secret: keep the provider's own formatting rather than re-encoding it.
                return $redacted === $decoded ? $value : (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $value = $set->mask($value);

        $value = (string) preg_replace(self::SENSITIVE_VALUE_PATTERNS, self::MASK, $value);
        $value = (string) preg_replace(self::CLIENT_SECRET_PATTERN, '$1'.self::MASK, $value);
        $value = (string) preg_replace(self::URL_USERINFO_PATTERN, '$1'.self::MASK.'@', $value);
        $value = (string) preg_replace(self::CLI_CREDENTIAL_PATTERN, '$1'.self::MASK, $value);

        if (stripos($value, '%3D') !== false) {
            $value = (string) preg_replace_callback(
                self::ENCODED_PAIR_PATTERN,
                fn (array $match): string => $this->isSensitiveKey($match[2], $sensitiveKeys) ? $match[1].self::MASK : $match[0],
                $value,
            );
        }

        return strpbrk($value, '=:<') === false ? $value : $this->scan($value, $sensitiveKeys);
    }

    /**
     * One left-to-right pass. A secret pair's value is masked and skipped; any other pair's name and separator are
     * copied and its value is scanned like the rest of the text, so a pair behind any number of labels is found
     * ("a:b:c:api-key=…", a URL inside a message). XML elements named like a secret, or labelled by a `key` or `name`
     * attribute that is (OpenSRS `<item key="reg_password">`), have their content masked, CDATA included.
     *
     * @param  list<string>  $sensitiveKeys
     */
    private function scan(string $value, array $sensitiveKeys): string
    {
        $this->forgetRuns(true);

        try {
            return $this->scanText($value, $sensitiveKeys);
        } finally {
            $this->forgetRuns(false);
        }
    }

    /** Clears the caches of one scan (see $scanning). */
    private function forgetRuns(bool $scanning): void
    {
        $this->scanning = $scanning;
        $this->unquotedRun = $this->lineRun = $this->cdataClose = $this->cdataOpen = $this->stoppedMask = null;
    }

    /**
     * @param  list<string>  $sensitiveKeys
     */
    private function scanText(string $value, array $sensitiveKeys): string
    {
        $length = strlen($value);
        $position = 0;
        $output = '';
        /** @var list<int>|null $endTags offsets of every `</`, found once when the first secret element needs them */
        $endTags = null;
        $endTagCursor = 0;
        /** @var array<string, int> $lastEndTag offset of the last `</name` per void element name, found once per name */
        $lastEndTag = [];
        $markers = new HtmlDocumentMarker($value);

        while ($position < $length && preg_match(self::TOKEN_PATTERN, $value, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $position) === 1) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            $output .= substr($value, $position, $start - $position);
            $position = $end;

            if ($match[5][0] !== null) {
                $output .= $match[0][0];

                if ($this->isSecretPair((string) $match[5][0], (string) $match[6][0], $value, $end, $sensitiveKeys)) {
                    $valueEnd = $this->pairValueEnd((string) $match[5][0], $value, $end);

                    // A value holding a mask runs on as it would without the set; a pair inside it that the scan would
                    // have read after the mask keeps its value masked.
                    if (str_contains(substr($value, $end, $valueEnd - $end), self::MASK)) {
                        $valueEnd = $this->innerPairsEnd($value, $this->maskBoundEnd((string) $match[5][0], $value, $end, $valueEnd), $valueEnd, $sensitiveKeys);
                    }
                    $position = $this->maskTailPairs($value, $end, $valueEnd, $sensitiveKeys, $output, $markers);
                }

                continue;
            }

            $closed = $match[1][0] === '/' || str_ends_with(rtrim((string) $match[4][0]), '/');
            $hasText = ! $closed && $this->textFollows($value, $end);
            [$attributes, $labelledSecret] = $this->maskAttributes((string) $match[4][0], $sensitiveKeys, $hasText);
            $output .= '<'.$match[1][0].$match[2][0].$attributes.'>';

            if ($closed) {
                continue;
            }

            if (! $labelledSecret && ! $this->isSensitiveKey((string) $match[3][0], $sensitiveKeys)) {
                continue;
            }

            // A void-named element (`<param name="password">`) used as a container, with a `</param` later in the text,
            // takes the end-tag path below so child elements, comments and CDATA inside it are masked too.
            $voidName = strtolower((string) $match[3][0]);

            if ((string) $match[2][0] === (string) $match[3][0] && in_array($voidName, self::VOID_ELEMENTS, true)
                && ($lastEndTag[$voidName] ??= (int) strripos($value, '</'.$voidName)) < $end) {
                // A marker counts when it lies before this element and outside every span masked so far.
                $contentEnd = $markers->before($start)
                    ? $this->voidContentEnd($value, $end)
                    : $this->voidContainerEnd($value, $end);
                $content = substr($value, $end, $contentEnd - $end);
                $output .= trim($content) === '' ? $content : self::MASK;
                $markers->skip($end, $contentEnd);

                $position = $contentEnd;

                continue;
            }

            // The end tag is searched forward through one index of `</` offsets. Found: the scan jumps past every end tag
            // it looked at. Not found: the rest of the text is masked (fail closed) and the scan ends. Either way each
            // end tag is looked at once, so the search is linear overall.
            $endTags ??= $this->endTagOffsets($value);
            $endTagCount = count($endTags);

            while ($endTagCursor < $endTagCount && $endTags[$endTagCursor] < $end) {
                $endTagCursor++;
            }

            $contentEnd = $length;

            for (; $endTagCursor < $endTagCount; $endTagCursor++) {
                if ($this->endTagCloses($value, $endTags[$endTagCursor], (string) $match[2][0])) {
                    $contentEnd = $endTags[$endTagCursor];

                    break;
                }
            }

            $content = substr($value, $end, $contentEnd - $end);
            $output .= trim($content) === '' ? $content : self::MASK;
            $markers->skip($end, $contentEnd);

            $position = $contentEnd;
        }

        return $output.substr($value, $position);
    }

    /**
     * A secret value can run on through a soft end or a `:` into the next pair's name (`pw=ab;token: x`, since
     * `passwd=ab;cd` is one value), and that pair's value after the blank would stay readable. While the masked span
     * ends in a secret pair's name and separator (trailingPair), the value after it is read with the usual pair rules
     * (valueEnd: quotes, brackets, an authorization scheme, `=` or `:` inside) and masked whole, and so on along a chain.
     * The name stays inside the first mask. A quoted or bracketed value that a separator follows, or a quoted one that
     * holds a `<`, may be the next pair's name or span markup, so the chain stops before it and the scan reads it as
     * before. So this only ever masks more. Each step reads at most 255 bytes back and moves forward, so the cost stays
     * linear.
     *
     * @param  list<string>  $sensitiveKeys
     * @return int where the scan resumes
     */
    private function maskTailPairs(string $value, int $from, int $to, array $sensitiveKeys, string &$output, HtmlDocumentMarker $markers): int
    {
        $output .= $this->maskSpan(substr($value, $from, $to - $from));
        $markers->skip($from, $to);

        while (($pair = $this->trailingPair($value, $from, $to, $sensitiveKeys)) !== null) {
            [$valueStart, $valueEnd] = $pair;
            $valueEnd = $this->innerPairsEnd($value, $valueStart, $valueEnd, $sensitiveKeys);
            $output .= substr($value, $to, $valueStart - $to).$this->maskSpan(substr($value, $valueStart, $valueEnd - $valueStart));
            $markers->skip($valueStart, $valueEnd);
            [$from, $to] = [$valueStart, $valueEnd];
        }

        return $to;
    }

    /**
     * The secret pair whose name ends the masked span [$from, $to) and whose value starts at or after $to: where that
     * value starts and ends, or null when there is none or the value is not one the chain takes (see maskTailPairs). A
     * name starts where TOKEN_PATTERN could match (a letter after a character that is not a letter, digit, `_` or `.`),
     * at most 255 bytes back, and must end at the span's end or just before a final `=` or `:`. The last such start is
     * tried, then the first, which names the whole run (`abcsecret]x: …` reads as one secret name, as before).
     *
     * @param  list<string>  $sensitiveKeys
     * @return array{int, int}|null
     */
    private function trailingPair(string $value, int $from, int $to, array $sensitiveKeys): ?array
    {
        $length = strlen($value);

        if ($to <= $from || $to >= $length) {
            return null;
        }

        $nameEnd = $to - (str_contains('=:', $value[$to - 1]) ? 1 : 0);
        $nameEnd -= min(16, strspn(strrev(substr($value, max($from, $nameEnd - 16), $nameEnd - max($from, $nameEnd - 16))), " \t"));
        // ASCII only, as in TOKEN_PATTERN (ctype_* follows the locale).
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $starts = [];

        for ($start = $nameEnd - 1; $start >= max($from, $nameEnd - 255); $start--) {
            if (! str_contains($letters.'0123456789_.-[]', $value[$start])) {
                break;
            }

            if (str_contains($letters, $value[$start]) && ($start === 0 || ! str_contains($letters.'0123456789_.', $value[$start - 1]))) {
                $starts[] = $start;
            }
        }

        foreach (array_unique([$starts[0] ?? null, $starts[count($starts) - 1] ?? null]) as $start) {
            if ($start === null || preg_match(self::TAIL_PAIR_AT, $value, $pair, 0, $start) !== 1 || strlen($pair[1]) !== $nameEnd - $start) {
                continue;
            }

            $valueStart = $start + strlen($pair[0]);

            if ($valueStart < $to || $valueStart >= $length || ! $this->isSecretPair($pair[1], $pair[2], $value, $valueStart, $sensitiveKeys)) {
                continue;
            }

            $valueEnd = $this->pairValueEnd($pair[1], $value, $valueStart);
            $opensAt = $valueStart + (preg_match(self::AUTH_SCHEME_PATTERN, $value, $scheme, 0, $valueStart) === 1 ? strlen($scheme[0]) : 0);
            $enclosed = str_contains('"\'\\[{&', $value[$opensAt] ?? '') && substr_compare($value, self::MASK, $opensAt, strlen(self::MASK)) !== 0;

            if ($valueEnd <= $valueStart || ($enclosed && (preg_match('/\G[ \t]*+(?:=>?|:)/', $value, $separator, 0, $valueEnd) === 1
                || str_contains(substr($value, $valueStart, $valueEnd - $valueStart), '<')))) {
                return null;
            }

            return [$valueStart, $valueEnd];
        }

        return null;
    }

    /**
     * Where a value [$start, $end) masked whole must end so that it also covers the value of any secret pair the scan
     * would have read in it (`1&hash:Bearer x`, `a,token:'x y'`) and masked past $end. For a tail value that is every
     * pair from $start (before #462 the tail stopped at the first one). For a value holding a mask it is every pair from
     * maskBoundEnd(), where the value ended when a mask could end it: a pair before that was inside the value and never
     * read, and extending through it could hide a tag the scan reads (`pass=[<t name="pass">]`). For the same reason the
     * search goes on after each inner value, at its maskBoundEnd(), not inside it. Matches are searched in windows of 256
     * bytes plus 280 for a
     * name and separator. An unquoted or whole-line inner value costs nothing past the first (valueEnd and pairValueEnd
     * remember the run they found); quoted and bracketed ones are counted, and past INNER_WORK times the span the rest
     * of the text is masked (fail closed). So the cost stays linear.
     *
     * @param  list<string>  $sensitiveKeys
     */
    private function innerPairsEnd(string $value, int $start, int $end, array $sensitiveKeys): int
    {
        $work = 0;

        for ($offset = $start; $offset < $end;) {
            $from = max(0, $offset - 1);
            $window = substr($value, $from, min($end - $offset, 256) + 281);

            if (preg_match(self::TOKEN_PATTERN, $window, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset - $from) !== 1
                || $from + $match[0][1] >= min($end, $offset + 256)) {
                $offset = min($end, $offset + 256);

                continue;
            }

            $matchEnd = $from + $match[0][1] + strlen($match[0][0]);
            $offset = $matchEnd;

            if ($match[5][0] === null || ! $this->isSecretPair((string) $match[5][0], (string) $match[6][0], $value, $matchEnd, $sensitiveKeys)) {
                continue;
            }

            $innerEnd = $this->pairValueEnd((string) $match[5][0], $value, $matchEnd);

            if ($this->opensEnclosed($value, $matchEnd)) {
                $work += $innerEnd - $matchEnd;

                if ($work > self::INNER_WORK * ($end - $start) + self::INNER_WORK_FLOOR) {
                    return strlen($value);
                }
            }

            // As the scan did before #462, the search goes on where that value ended then: a pair inside it was never read.
            $end = max($end, $innerEnd);
            $offset = max($offset, $this->maskBoundEnd((string) $match[5][0], $value, $matchEnd, $innerEnd));
        }

        return $end;
    }

    /**
     * Where the value of the secret pair $name, read by pairValueEnd() as [$start, $end), ended before #462, when the `]`
     * of a mask followed by a value end, or a mask at the start, ended an unquoted value and a CDATA section was not
     * part of it. Quoted, bracketed and whole-line values end where they did.
     */
    private function maskBoundEnd(string $name, string $value, int $start, int $end): int
    {
        if (in_array(strtolower(rtrim($name, '[]')), self::WHOLE_LINE_KEYS, true) || $this->opensEnclosed($value, $start)) {
            return $end;
        }

        $at = $start + (preg_match(self::AUTH_SCHEME_PATTERN, $value, $scheme, 0, $start) === 1 ? strlen($scheme[0]) : 0);

        if (substr_compare($value, self::MASK, $at, strlen(self::MASK)) === 0) {
            return min($end, $at + strlen(self::MASK));
        }

        $cdata = self::nextFrom($this->cdataOpen, $at, fn (int $from): int|false => strpos($value, '<![CDATA[', $from), $this->scanning);
        $mask = self::nextFrom($this->stoppedMask, $at, fn (int $from): int|false => $this->stoppedMask($value, $from), $this->scanning);

        return min($end, $cdata === false ? $end : $cdata, $mask === false ? $end : $mask + strlen(self::MASK));
    }

    /** The offset of the first mask at or after $from that a value end follows (or the end of the text). */
    private function stoppedMask(string $value, int $from): int|false
    {
        $stops = self::VALUE_HARD_END.self::VALUE_SOFT_END.'&';

        for ($mask = strpos($value, self::MASK, $from); $mask !== false; $mask = strpos($value, self::MASK, $mask + 1)) {
            $after = $mask + strlen(self::MASK);

            if ($after >= strlen($value) || str_contains($stops, $value[$after])) {
                return $mask;
            }
        }

        return false;
    }

    /**
     * The first match at or after $from that $find returns, remembered in $cache during a scan: a later $from up to that
     * match has the same first match, so searches that start inside one never repeat it.
     *
     * @param  array{int, int|false}|null  $cache
     * @param  Closure(int): (int|false)  $find
     */
    private static function nextFrom(?array &$cache, int $from, Closure $find, bool $remember): int|false
    {
        if ($cache !== null && $from >= $cache[0] && ($cache[1] === false || $from <= $cache[1])) {
            return $cache[1];
        }

        $found = $find($from);
        $cache = $remember ? [$from, $found] : null;

        return $found;
    }

    /** Whether valueEnd() reads the value at $valueStart as quoted or bracketed rather than as an unquoted run. */
    private function opensEnclosed(string $value, int $valueStart): bool
    {
        $at = $valueStart + (preg_match(self::AUTH_SCHEME_PATTERN, $value, $scheme, 0, $valueStart) === 1 ? strlen($scheme[0]) : 0);
        $first = $value[$at] ?? '';

        return $first === '"' || $first === "'" || $first === '{'
            || ($first === '\\' && in_array($value[$at + 1] ?? '', ['"', "'"], true))
            || ($first === '[' && substr_compare($value, self::MASK, $at, strlen(self::MASK)) !== 0)
            || substr_compare($value, '&quot;', $at, 6) === 0;
    }

    /**
     * Where a secret pair's value ends: the line for a whole-line key (WHOLE_LINE_KEYS), otherwise valueEnd(). A start
     * inside the line found last ends where it does (the scan's cache, see $lineRun).
     */
    private function pairValueEnd(string $name, string $value, int $valueStart): int
    {
        if (! in_array(strtolower(rtrim($name, '[]')), self::WHOLE_LINE_KEYS, true)) {
            return $this->valueEnd($value, $valueStart);
        }

        if ($this->lineRun !== null && $valueStart >= $this->lineRun[0] && $valueStart < $this->lineRun[1]) {
            return $this->lineRun[1];
        }

        $lineEnd = $valueStart + strcspn($value, "\r\n", $valueStart);
        $this->lineRun = $this->scanning ? [$valueStart, $lineEnd] : null;

        return $lineEnd;
    }

    /**
     * Whether a pair's value, starting at $valueStart, is secret. Two exceptions to the name rule:
     * - `Session:` and `hash:` as an unquoted label keep a value readable only when the whole value is a known
     *   reference id (REFERENCE_ID_PATTERN), such as a Stripe Checkout Session id, optionally ending a sentence with
     *   `.`; anything joined to the id (`cs_live_x:…`, `%3A…`, `,…`) makes the value secret.
     * - An unquoted name whose separator runs past a line end takes the next line as its value unless that line is
     *   header-shaped (a name, `:`, blanks, then a value on that line), so an empty `Cookie:` keeps the `Host: a.test`
     *   line after it while `password:\nvalue`, `password:\nhunter2:`, `token:\nQWx…==` and `secret:\na:b` are masked.
     *
     * @param  list<string>  $sensitiveKeys
     */
    private function isSecretPair(string $name, string $separator, string $value, int $valueStart, array $sensitiveKeys): bool
    {
        $name = rtrim($name, '[]');

        if (! $this->isSensitiveKey($name, $sensitiveKeys)) {
            return false;
        }

        $quoted = strpbrk($separator[0] ?? '', '"\'\\&') !== false;
        $lineBreak = strrpos($separator, "\n");

        if (! $quoted && $lineBreak !== false) {
            if (preg_match('/\G[A-Za-z][A-Za-z0-9_.\-]*+[ \t]*+:[ \t]++(?=[^ \t\r\n])/', $value, $pair, 0, $valueStart) === 1) {
                return false;
            }
        }

        if (! $quoted && in_array(strtolower($name), self::REFERENCE_LABELS, true) && str_starts_with(ltrim($separator, " \t"), ':')
            && preg_match(self::REFERENCE_ID_PATTERN, $value, $reference, 0, $valueStart) === 1) {
            $idEnd = $valueStart + strlen($reference[0]);
            $valueEnd = $this->valueEnd($value, $valueStart);

            if ($valueEnd === $idEnd || ($valueEnd === $idEnd + 1 && $value[$idEnd] === '.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where the content of a secret void-named element in an HTML document starts at $start ends: past a CDATA section
     * after optional blanks (an unterminated one runs to the end, failing closed), otherwise at the next `<`.
     */
    private function voidContentEnd(string $value, int $start): int
    {
        $length = strlen($value);
        $first = $start + strspn($value, " \t\r\n", $start);

        if ($first < $length && substr_compare($value, '<![CDATA[', $first, 9) === 0) {
            $close = strpos($value, ']]>', $first + 9);

            return $close === false ? $length : $close + 3;
        }

        $next = strpos($value, '<', $start);

        return $next === false ? $length : $next;
    }

    /**
     * Where the content of a secret void-named element outside an HTML document ends. Such an element is an XML
     * container (`<param name="password"><value><string>…`) that no end tag bounds, so after only blanks, comments,
     * empty CDATA sections and stray end tags, a child start tag (a name starting with a letter, `_`, `:` or a
     * non-ASCII byte), `<!` or `<?` masks the rest of the text (fail closed). Text, a non-empty CDATA section or an end
     * tag followed by neither ends the content there. Every step moves forward, so the cost is linear.
     */
    private function voidContainerEnd(string $value, int $start): int
    {
        $length = strlen($value);
        $position = $start;
        $bound = null;

        while (true) {
            $position += strspn($value, " \t\r\n", $position);

            if ($position >= $length) {
                return $bound ?? $length;
            }

            if ($value[$position] !== '<') {
                return $bound ?? ($position + strcspn($value, '<', $position));
            }

            $after = $value[$position + 1] ?? '';

            if (substr_compare($value, '<!--', $position, 4) === 0) {
                $close = strpos($value, '-->', $position + 4);

                if ($close === false) {
                    return $length;
                }

                $position = $close + 3;
            } elseif (substr_compare($value, '<![CDATA[', $position, 9) === 0) {
                $close = strpos($value, ']]>', $position + 9);

                if ($close === false) {
                    return $length;
                }

                if (trim(substr($value, $position + 9, $close - $position - 9)) !== '') {
                    return $bound ?? $close + 3;
                }

                $position = $close + 3;
            } elseif ($after === '/') {
                $bound ??= $position;
                $close = $position + 1 + strcspn($value, '<>', $position + 1);

                // The end tag's `>` must come before the next `<`; otherwise stop here, so a later pass never walks this span again.
                if ($close >= $length || $value[$close] !== '>') {
                    return $bound;
                }

                $position = $close + 1;
            } elseif ($after === '!' || $after === '?' || HtmlDocumentMarker::nameLength($value, $position + 1) > 0) {
                return $length;
            } else {
                return $bound ?? $position;
            }
        }
    }

    /**
     * Offsets of every `</` in the text, in order.
     *
     * @return list<int>
     */
    private function endTagOffsets(string $value): array
    {
        $offsets = [];

        for ($offset = strpos($value, '</'); $offset !== false; $offset = strpos($value, '</', $offset + 2)) {
            $offsets[] = $offset;
        }

        return $offsets;
    }

    /** Whether the `</` at $offset ends the element $name (case-insensitive, followed by `>` or a space). */
    private function endTagCloses(string $value, int $offset, string $name): bool
    {
        $after = $value[$offset + 2 + strlen($name)] ?? '';

        return ($after === '>' || ctype_space($after)) && substr_compare($value, $name, $offset + 2, strlen($name), true) === 0;
    }

    /**
     * Where a secret pair's value starting at $start ends: a quoted string (escapes honoured, unterminated runs to the
     * end), a JSON array or object, or an unquoted run, after an optional authorization scheme. An unquoted value runs
     * through `;` and `,` that are followed by more value (`passwd=ab;cd`), so only a list separator ends it. A mask
     * already in the value (the set's, or a provider's) counts as value characters, so the value ends where it would
     * without the set (scan() then covers any pair inside it, see innerPairsEnd). A CDATA section the value runs into is
     * part of it (cdataEnd).
     */
    private function valueEnd(string $value, int $start): int
    {
        $length = strlen($value);

        if (preg_match(self::AUTH_SCHEME_PATTERN, $value, $scheme, 0, $start) === 1) {
            $start += strlen($scheme[0]);
        }

        $first = $value[$start] ?? '';

        if ($first === '"' || $first === "'") {
            return $this->quotedEnd($value, $start + 1, $first);
        }

        if ($first === '\\' && in_array($value[$start + 1] ?? '', ['"', "'"], true)) {
            $close = strpos($value, '\\'.$value[$start + 1], $start + 2);

            return $close === false ? $length : $close + 2;
        }

        if (($first === '[' && substr_compare($value, self::MASK, $start, strlen(self::MASK)) !== 0) || $first === '{') {
            return $this->bracketEnd($value, $start);
        }

        if (substr_compare($value, '&quot;', $start, 6) === 0) {
            $close = strpos($value, '&quot;', $start + 6);

            return $close === false ? $length : $close + 6;
        }

        // Whether an unquoted run stops at an index depends on that index only, so a start inside the run found last
        // ends where that run does.
        if ($this->unquotedRun !== null && $start >= $this->unquotedRun[0] && $start < $this->unquotedRun[1]) {
            return $this->unquotedRun[1];
        }

        $runEnd = $this->unquotedEnd($value, $start);
        $this->unquotedRun = $this->scanning ? [$start, $runEnd] : null;

        return $runEnd;
    }

    /** Where an unquoted value starting at $start ends (see valueEnd). */
    private function unquotedEnd(string $value, int $start): int
    {
        $length = strlen($value);
        $stops = self::VALUE_HARD_END.self::VALUE_SOFT_END.'&';

        for ($index = $start; ; $index++) {
            $index += strcspn($value, $stops, $index);

            if ($index < $length && $value[$index] === '&') {
                if (preg_match(self::AMPERSAND_END, $value, $ampersand, 0, $index) === 1) {
                    return $index;
                }

                continue;
            }

            if ($index < $length && $value[$index] === '<' && substr_compare($value, '<![CDATA[', $index, 9) === 0) {
                return $this->cdataEnd($value, $index);
            }

            if ($index >= $length || ! str_contains(self::VALUE_SOFT_END, $value[$index])) {
                return min($index, $length);
            }

            // The `]` of a mask already in the value is a value character, so the mask is replaced whole (#399) and the
            // value ends where it would without the set.
            $closesMask = $value[$index] === ']' && $index - $start >= strlen(self::MASK) - 1
                && substr_compare($value, self::MASK, $index - strlen(self::MASK) + 1, strlen(self::MASK)) === 0;
            $next = $value[$index + 1] ?? '';

            if (! $closesMask && ($next === '' || str_contains($stops, $next))) {
                return $index;
            }
        }
    }

    /**
     * Where an unquoted value that reaches a CDATA section at $at ends: past the section, which is part of the value,
     * or at the end of the text when it is unterminated (fail closed). A section that holds a quote, bracket, `&`, `\`,
     * `<` or a cookie header could start a pair whose value the scan reads past `]]>`, so the value ends before it and
     * the scan reads it as before. The content is read up to its first such character, and the `]]>` found last is
     * remembered during a scan ($cdataClose), so many sections that share one far `]]>` stay linear.
     */
    private function cdataEnd(string $value, int $at): int
    {
        $from = $at + 9;
        $close = $this->cdataClose !== null && $from >= $this->cdataClose[0] && ($this->cdataClose[1] === false || $from <= $this->cdataClose[1])
            ? $this->cdataClose[1]
            : strpos($value, ']]>', $from);
        $this->cdataClose = $this->scanning ? [$from, $close] : null;

        if ($close === false) {
            return strlen($value);
        }

        if (strcspn($value, '"\'\\[{&<', $from, $close - $from) < $close - $from) {
            return $at;
        }

        return stripos(substr($value, $from, $close - $from), 'cookie') === false ? $close + 3 : $at;
    }

    /** The offset just past the quote that closes a string opened before $start. */
    private function quotedEnd(string $value, int $start, string $quote): int
    {
        $length = strlen($value);

        for ($index = $start; $index < $length; $index += 2) {
            $index += strcspn($value, $quote.'\\', $index);

            if ($index >= $length) {
                break;
            }

            if ($value[$index] === $quote) {
                return $index + 1;
            }
        }

        return $length;
    }

    /** The offset just past the bracket that closes the JSON array or object at $start (strings skipped). */
    private function bracketEnd(string $value, int $start): int
    {
        $length = strlen($value);
        $depth = 0;

        for ($index = $start; $index < $length;) {
            $index += strcspn($value, '[]{}"', $index);

            if ($index >= $length) {
                break;
            }

            if ($value[$index] === '"') {
                $index = $this->quotedEnd($value, $index + 1, '"');

                continue;
            }

            $depth += $value[$index] === '[' || $value[$index] === '{' ? 1 : -1;
            $index++;

            if ($depth === 0) {
                return $index;
            }
        }

        return $length;
    }

    /** The mask in place of a value, keeping its quotes so JSON and attribute text keep their shape. */
    private function maskSpan(string $span): string
    {
        if ($span === '' || $span === self::MASK) {
            return $span;
        }

        foreach (['&quot;', '\\"', "\\'", '"', "'"] as $quote) {
            if (str_starts_with($span, $quote)) {
                $inner = substr($span, strlen($quote));
                $closed = strlen($inner) >= strlen($quote) && str_ends_with($inner, $quote);
                $inner = $closed ? substr($inner, 0, -strlen($quote)) : $inner;

                return $inner === '' ? $span : $quote.self::MASK.($closed ? $quote : '');
            }
        }

        return self::MASK;
    }

    /**
     * Whether non-blank text follows a start tag ending at $at: after any blanks, a character other than `<`, or a CDATA
     * section with a non-blank character. Child elements, comments, end tags and blank CDATA are not text. Only the
     * blanks after the tag and at the start of a CDATA section are read, and the next token starts after them, so the
     * scan stays linear.
     */
    private function textFollows(string $value, int $at): bool
    {
        $length = strlen($value);
        $at += strspn($value, " \t\r\n", $at);

        if ($at >= $length) {
            return false;
        }

        if ($value[$at] !== '<') {
            return true;
        }

        if (substr_compare($value, '<![CDATA[', $at, 9) !== 0) {
            return false;
        }

        $at += 9 + strspn($value, " \t\r\n", $at + 9);

        return $at < $length && substr_compare($value, ']]>', $at, 3) !== 0;
    }

    /**
     * Mask the secret attributes of one tag, and scan the others' values for pairs (a URL with `?token=`). A `key` or
     * `name` attribute stays readable as a label only when the element carries its value elsewhere: a non-blank `value`
     * attribute, or text right after the start tag (see textFollows). Otherwise it may be the element's only value
     * (`<license key="…"/>`, `<license key="…"><child/></license>`, `<x key="…" value=""/>`) and is treated like any
     * other attribute. Either way, when it names a secret the element's content and any `value` or `content` attribute
     * are masked.
     *
     * @param  list<string>  $sensitiveKeys
     * @return array{string, bool} the attributes and whether a key/name attribute names a secret
     */
    private function maskAttributes(string $attributes, array $sensitiveKeys, bool $hasText): array
    {
        if ($attributes === '' || ! str_contains($attributes, '=')) {
            return [$attributes, false];
        }

        preg_match_all(self::ATTRIBUTE_PATTERN, $attributes, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $labels = $hasText;

        foreach ($found as $attribute) {
            $labels = $labels || (strtolower($attribute[1][0]) === 'value' && trim($attribute[3][0], "\"' \t\r\n") !== '');
        }

        $labelledSecret = false;

        foreach ($found as $attribute) {
            if (in_array(strtolower($attribute[1][0]), self::LABEL_ATTRIBUTES, true) && $this->isSensitiveKey(trim($attribute[3][0], '"\''), $sensitiveKeys)) {
                $labelledSecret = true;
            }
        }

        $output = '';
        $position = 0;

        foreach ($found as $attribute) {
            [$name, $raw] = [strtolower($attribute[1][0]), $attribute[3][0]];
            $output .= substr($attributes, $position, $attribute[3][1] - $position);
            $position = $attribute[3][1] + strlen($raw);

            $output .= match (true) {
                $labels && in_array($name, self::LABEL_ATTRIBUTES, true) => $raw,
                ($labelledSecret && in_array($name, self::VALUE_ATTRIBUTES, true)) || $this->isSensitiveKey($attribute[1][0], $sensitiveKeys) => $this->maskSpan($raw),
                default => strpbrk($raw, '=:') === false ? $raw : $this->scan($raw, $sensitiveKeys),
            };
        }

        return [$output.substr($attributes, $position), $labelledSecret];
    }

    /**
     * @return list<string>
     */
    private function words(string $key): array
    {
        $words = preg_split('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map('strtolower', $words);
    }

    private function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $index => $digit) {
            $doubled = (int) $digit * ($index % 2 === 1 ? 2 : 1);
            $sum += $doubled > 9 ? $doubled - 9 : $doubled;
        }

        return $sum % 10 === 0;
    }
}
