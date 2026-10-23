<?php

namespace App\Support\Integrations;

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
     * Every repeat is possessive or bounded, so no input backtracks more than a constant per position.
     */
    private const TOKEN_PATTERN = '/<(\/?)((?:[A-Za-z_][\w.\-]*+:)?([A-Za-z_][\w.\-]*+))([^<>]*+)>|(?<![A-Za-z0-9_.])([A-Za-z][A-Za-z0-9_.\-\[\]]{0,254}+)((?:\\\\?["\']|&quot;)?[ \t]{0,16}+(?:=>?|:)\s*+)(?!\/\/)/';

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
     * An element with no end tag keeps that next-tag bound only in an HTML document (nextHtmlMarker finds a
     * `<!doctype html` or `<html` start tag at markup level before it); anywhere else it is an XML container and a child element after it fails closed (voidContainerEnd).
     */
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Header names whose whole line is secret: every cookie on it (`Cookie: theme=dark; cpsession=…`). */
    private const WHOLE_LINE_KEYS = ['cookie', 'set-cookie'];

    /** `curl -u user:password` and the proxy form: the credentials after the flag, quoted or attached (`-uroot:pw`). */
    private const CLI_CREDENTIAL_PATTERN = '/(?<![^\s])((?:-u|-U|--user|--proxy-user)(?:[ \t]++|=)?)(?:\'[^\']*+\'|"[^"]*+"|[^\s"\':]*+:[^\s"\']++)/';

    /** Attributes that name the element's field (OpenSRS `<item key="…">`, `<param name="…">`) rather than hold a value. */
    private const LABEL_ATTRIBUTES = ['key', 'name'];

    /** An unquoted value ends at one of these, or at an `&` that starts the next pair (see AMPERSAND_END)... */
    private const VALUE_HARD_END = " \t\r\n\f\v\"<>";

    /** ...or at one of these when it is followed by a space, a quote, another end character or the end of the text. */
    private const VALUE_SOFT_END = ',;)]}\'';

    /** Names remembered by isSensitiveKey() before the cache starts over. */
    private const KEY_CACHE_SIZE = 2048;

    /** @var array<string, bool> */
    private array $keyCache = [];

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
        return $this->redactWith($payload, self::set($secrets), array_map('strtolower', $sensitiveKeys), 0);
    }

    /**
     * Payment provider payloads: mask every leaf under a payment-sensitive key (null included), walk nested arrays and
     * objects, and mask Luhn-valid card numbers inside text.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redactPayment(array $payload, int $depth = 0): array
    {
        foreach ($payload as $key => $value) {
            $value = $this->unwrap($value, $depth);

            if ($depth >= self::MAX_DEPTH && (is_array($value) || is_object($value))) {
                $payload[$key] = self::MASK;
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPayment($value, $depth + 1);
            } elseif (is_string($key) && ! in_array($key, self::PAYMENT_REFERENCES, true) && preg_match(self::PAYMENT_KEYS, $key) === 1) {
                $payload[$key] = self::MASK;
            } else {
                $payload[$key] = is_string($value) ? $this->scrubCardNumbers($this->maskString($value, RedactionSet::empty(), [], $depth)) : $value;
            }
        }

        return $payload;
    }

    /**
     * @param  RedactionSet|list<string>  $secrets
     */
    public function maskValues(string $value, RedactionSet|array $secrets): string
    {
        return $this->maskString($value, self::set($secrets), [], 0);
    }

    /**
     * Masks, in place, the message of $exception and of up to 16 exceptions behind it, with the shape rules and the
     * set's values; any exception further down the chain has its message withheld whole (fail closed). The class,
     * code, trace, flags and `previous` are kept, so the caller reports the original.
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
        $set = self::set($secrets ?? []);
        $seen = new \SplObjectStorage;

        for ($link = $exception, $hops = 0; $link !== null && ! $seen->contains($link); $link = $link->getPrevious(), $hops++) {
            $seen->attach($link);
            $message = $link->getMessage();
            $masked = $hops <= self::MAX_PREVIOUS ? $this->maskString($message, $set, [], 0) : self::MASK;

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
    private function redactWith(array $payload, RedactionSet $set, array $sensitiveKeys, int $depth): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $value = $this->unwrap($value, $depth);

            $masked = match (true) {
                $value !== null && $value !== '' && $value !== [] && $this->isSensitiveKey($key, $sensitiveKeys) => self::MASK,
                is_array($value) => $depth >= self::MAX_DEPTH ? self::MASK : $this->redactWith($value, $set, $sensitiveKeys, $depth + 1),
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
        $length = strlen($value);
        $position = 0;
        $output = '';
        /** @var list<int>|null $endTags offsets of every `</`, found once when the first secret element needs them */
        $endTags = null;
        $endTagCursor = 0;
        /** @var array<string, int> $lastEndTag offset of the last `</name` per void element name, found once per name */
        $lastEndTag = [];
        /** @var int|null $markerFrom where the HTML marker walk resumes (null: no marker text in the text, or the walk is done) */
        $markerFrom = stripos($value, '<html') === false && stripos($value, '<!doctype') === false ? null : 0;
        $htmlSeen = false;
        // Walks the text for an HTML marker that starts before $limit; a marker inside a masked span is never reached,
        // because the walk jumps over each span as it is masked.
        $findMarker = function (int $limit) use ($value, &$markerFrom, &$htmlSeen): ?int {
            $htmlSeen = $htmlSeen || ($markerFrom !== null && $this->nextHtmlMarker($value, $markerFrom, $limit) !== null);

            return $markerFrom;
        };
        $skipMasked = function (int $from, int $to) use ($findMarker, &$markerFrom, &$htmlSeen): void {
            if ($markerFrom === null || $htmlSeen) {
                return;
            }

            $resume = $findMarker($from);

            if ($resume !== null && $resume < $to) {
                $markerFrom = $to;
            }
        };

        while ($position < $length && preg_match(self::TOKEN_PATTERN, $value, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $position) === 1) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            $output .= substr($value, $position, $start - $position);
            $position = $end;

            if ($match[5][0] !== null) {
                $output .= $match[0][0];

                if ($this->isSecretPair((string) $match[5][0], (string) $match[6][0], $value, $end, $sensitiveKeys)) {
                    $valueEnd = in_array(strtolower((string) $match[5][0]), self::WHOLE_LINE_KEYS, true)
                        ? $end + strcspn($value, "\r\n", $end)
                        : $this->valueEnd($value, $end);
                    $output .= $this->maskSpan(substr($value, $end, $valueEnd - $end));
                    $skipMasked($end, $valueEnd);

                    $position = $valueEnd;
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
                $findMarker($start);

                $contentEnd = $htmlSeen
                    ? $this->voidContentEnd($value, $end)
                    : $this->voidContainerEnd($value, $end);
                $content = substr($value, $end, $contentEnd - $end);
                $output .= trim($content) === '' ? $content : self::MASK;
                $skipMasked($end, $contentEnd);

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
            $skipMasked($end, $contentEnd);

            $position = $contentEnd;
        }

        return $output.substr($value, $position);
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
            } elseif ($after === '!' || $after === '?' || ctype_alpha($after) || $after === '_' || $after === ':' || ($after !== '' && ord($after) >= 0x80)) {
                return $length;
            } else {
                return $bound ?? $position;
            }
        }
    }

    /**
     * The first HTML document marker at or after $from: a `<!doctype html` or an unprefixed `<html` start tag, any case.
     * One forward walk with strpos and strcspn, constant extra memory: comments, CDATA sections, processing
     * instructions and other declarations are skipped whole, and a tag's attributes are skipped with quoted values
     * honoured, so a marker inside them is not found. An unterminated construct runs to the end of the text. `<head`
     * and `<body` are not markers (SOAP has a `<Body>`). Only a marker that starts before $before is looked for. $from becomes
     * where the search resumes (it is the `<` where the walk stopped at $before, so a later call does not rescan), or null when the text has no more.
     */
    private function nextHtmlMarker(string $value, ?int &$from, int $before): ?int
    {
        $length = strlen($value);
        $position = $from;

        while (($open = strpos($value, '<', $position)) !== false) {
            if ($open >= $before) {
                $from = $open;

                return null;
            }

            $next = $value[$open + 1] ?? '';

            if ($next === '!' || $next === '?') {
                if (substr_compare($value, '<!--', $open, 4) === 0) {
                    $close = strpos($value, '-->', $open + 4);
                    $position = $close === false ? $length : $close + 3;
                } elseif (substr_compare($value, '<![CDATA[', $open, 9) === 0) {
                    $close = strpos($value, ']]>', $open + 9);
                    $position = $close === false ? $length : $close + 3;
                } elseif ($next === '?') {
                    $close = strpos($value, '?>', $open + 2);
                    $position = $close === false ? $length : $close + 2;
                } else {
                    $spaces = strspn($value, " \t\r\n\f", $open + 9);

                    if (substr_compare($value, '<!doctype', $open, 9, true) === 0 && $spaces > 0
                        && substr_compare($value, 'html', $open + 9 + $spaces, 4, true) === 0
                        && ! $this->isNameByte($value[$open + 13 + $spaces] ?? '')) {
                        $from = $this->skipTag($value, $open + 2);

                        return $open;
                    }

                    $position = $this->skipTag($value, $open + 2);
                }

                continue;
            }

            // A byte >= 0x80 starts a name here as in voidContainerEnd(), so `<é t="<html>">` is a tag, not text (#309).
            $nameLength = ctype_alpha($next) || $next === '_' || ($next !== '' && ord($next) >= 0x80)
                ? strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:-'.self::highBytes(), $open + 1)
                : 0;

            if ($nameLength === 0 && $next !== '/') {
                $position = $open + 1; // a `<` that starts no tag is text

                continue;
            }

            $position = $this->skipTag($value, $open + 1 + $nameLength);

            if ($nameLength === 4 && strcasecmp(substr($value, $open + 1, 4), 'html') === 0) {
                $from = $position;

                return $open;
            }
        }

        $from = null;

        return null;
    }

    /** Bytes 0x80 to 0xFF, which UTF-8 names are made of. */
    private static function highBytes(): string
    {
        static $bytes = null;

        return $bytes ??= implode('', array_map('chr', range(0x80, 0xFF)));
    }

    private function isNameByte(string $byte): bool
    {
        return $byte !== '' && (ctype_alnum($byte) || $byte === '_' || $byte === '.' || $byte === ':' || $byte === '-');
    }

    /** The offset after the `>` that ends the tag whose attributes start at $position; quoted values may hold `>`. An unterminated tag or quote runs to the end. */
    private function skipTag(string $value, int $position): int
    {
        $length = strlen($value);

        while (true) {
            $at = $position + strcspn($value, '>"\'', $position);

            if ($at >= $length) {
                return $length;
            }

            if ($value[$at] === '>') {
                return $at + 1;
            }

            $close = strpos($value, $value[$at], $at + 1);

            if ($close === false) {
                return $length;
            }

            $position = $close + 1;
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
     * through `;` and `,` that are followed by more value (`passwd=ab;cd`), so only a list separator ends it.
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

        if ($first === '[' || $first === '{') {
            return $this->bracketEnd($value, $start);
        }

        if (substr_compare($value, '&quot;', $start, 6) === 0) {
            $close = strpos($value, '&quot;', $start + 6);

            return $close === false ? $length : $close + 6;
        }

        $stops = self::VALUE_HARD_END.self::VALUE_SOFT_END.'&';

        for ($index = $start; ; $index++) {
            $index += strcspn($value, $stops, $index);

            if ($index < $length && $value[$index] === '&') {
                if (preg_match(self::AMPERSAND_END, $value, $ampersand, 0, $index) === 1) {
                    return $index;
                }

                continue;
            }

            if ($index >= $length || ! str_contains(self::VALUE_SOFT_END, $value[$index])) {
                return min($index, $length);
            }

            $next = $value[$index + 1] ?? '';

            if ($next === '' || str_contains($stops, $next)) {
                return $index;
            }
        }
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
     * other attribute. Either way, when it names a secret the element's content and any `value` attribute are masked.
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
                ($labelledSecret && $name === 'value') || $this->isSensitiveKey($attribute[1][0], $sensitiveKeys) => $this->maskSpan($raw),
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
