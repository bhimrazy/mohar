<?php

namespace App\Support\Integrations;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Removes secrets from integration logs and from messages shown to staff. All rules live here; the host redacts what
 * drivers return, and drivers never call this.
 *
 * - redact(): module and registrar command logs. A key is sensitive when one of its words (`api_key`, `api-key`,
 *   `ApiKey` and `APIKey` all read as "api key"), a pair of adjacent words, or a run-together spelling (`eppcode`,
 *   `accesshash`) names a secret; a sensitive key hides its whole value. Matching whole words keeps `author`,
 *   `country_code` and `keyword` visible. Known credential values, cPanel `cpsess…` tokens, and secret `name=value`
 *   pairs or JSON inside strings are masked wherever they appear.
 * - redactPayment(): payment provider payloads (gateway log, transaction and refund `raw`). Every leaf under a
 *   card, number, token, secret, signature, password or expiry key is masked, null included, while nested objects are
 *   walked so a card's brand and last 4 digits stay visible. Luhn-valid card numbers in text are masked.
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

    /** A key is sensitive when it is exactly one of these. */
    private const SENSITIVE_KEYS = ['key', 'pw', 'pwd', 'dv', 'phpsessid'];

    /** ...or when one of its words is one of these. */
    private const SENSITIVE_WORDS = [
        'password', 'passwd', 'pass', 'passphrase', 'secret', 'token', 'auth', 'authorization', 'signature', 'hash',
        'hmac', 'session', 'sessions', 'cookie', 'cookies', 'credential', 'credentials', 'epp', 'otp', 'apikey', 'cvc',
        'cvv', 'cvv2', 'pan', 'dv',
    ];

    /** ...or when two adjacent words are one of these pairs. */
    private const SENSITIVE_PAIRS = [
        ['api', 'key'], ['private', 'key'], ['access', 'key'], ['secret', 'key'], ['license', 'key'], ['licence', 'key'],
        ['ssh', 'key'], ['signing', 'key'], ['encryption', 'key'], ['auth', 'key'], ['transfer', 'code'],
        ['auth', 'code'], ['auth', 'info'], ['card', 'number'], ['account', 'number'], ['data', 'validation'],
    ];

    /** ...or when the key, lowercased and with separators removed, contains one of these run-together spellings. */
    private const RUN_TOGETHER = '/password|passwd|secret|token|apikey|signature|authorization|credential|eppcode|authcode|authinfo|authkey|accesshash|accesskey|privatekey|secretkey|hash|datavalidation/';

    /** Payment payload keys (develop's gateway rule, plus `authorization`, `dv` and `dataValidation`, payments-nepal.md §4). */
    private const PAYMENT_KEYS = '/card|pan|number|cvc|cvv|token|secret|signature|password|exp|authorization|datavalidation|^dv$/i';

    /** Payment keys that look sensitive but are references staff need. */
    private const PAYMENT_REFERENCES = ['txn_id', 'parent_txn_id'];

    /** Values that are secret wherever they appear, whatever the key (cPanel session tokens). */
    private const SENSITIVE_VALUE_PATTERNS = ['/cpsess\d+/'];

    /** @var array<string, bool> */
    private array $keyCache = [];

    /**
     * A sensitive key hides its whole value, nested arrays included. Null and empty values are kept, so the log
     * still shows that nothing was sent. Objects are read as arrays first (Arrayable, JsonSerializable, otherwise
     * their public properties), so a DTO in a payload cannot carry a secret past the mask.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $secrets  values to mask wherever they appear
     * @param  list<string>  $sensitiveKeys  extra exact keys that are secret in this payload only, e.g. `url` in an SSO
     *                                       answer or `code` in an EPP code answer
     * @return array<array-key, mixed>
     */
    public function redact(array $payload, array $secrets = [], array $sensitiveKeys = []): array
    {
        return $this->redactWith($payload, $this->variants($secrets), array_map('strtolower', $sensitiveKeys), 0);
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
            $value = $this->unwrap($value);

            if ($depth >= self::MAX_DEPTH && (is_array($value) || is_object($value))) {
                $payload[$key] = self::MASK;
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPayment($value, $depth + 1);
            } elseif (is_string($key) && ! in_array($key, self::PAYMENT_REFERENCES, true) && preg_match(self::PAYMENT_KEYS, $key) === 1) {
                $payload[$key] = self::MASK;
            } else {
                $payload[$key] = is_string($value) ? $this->scrubCardNumbers($value) : $value;
            }
        }

        return $payload;
    }

    /**
     * @param  list<string>  $secrets
     */
    public function maskValues(string $value, array $secrets): string
    {
        return $this->maskString($value, $this->variants($secrets), [], 0);
    }

    /**
     * Raw driver output (a provider's response body or error text) made safe to show staff: JSON is redacted by key,
     * any text has known secrets masked, and the result is cut to $limit bytes.
     *
     * @param  list<string>  $secrets
     */
    public function excerpt(string $raw, array $secrets = [], int $limit = self::EXCERPT_BYTES): string
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

        return $this->keyCache[$key] ??= $this->matchesSecretName($key, $lower);
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
     * @param  list<string>  $variants
     * @param  list<string>  $sensitiveKeys
     * @return array<array-key, mixed>
     */
    private function redactWith(array $payload, array $variants, array $sensitiveKeys, int $depth): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $value = $this->unwrap($value);

            if ($value !== null && $value !== '' && $value !== [] && $this->isSensitiveKey($key, $sensitiveKeys)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $depth >= self::MAX_DEPTH ? self::MASK : $this->redactWith($value, $variants, $sensitiveKeys, $depth + 1),
                is_string($value) => $this->maskString($value, $variants, $sensitiveKeys, $depth),
                default => $value,
            };
        }

        return $redacted;
    }

    private function unwrap(mixed $value): mixed
    {
        if (! is_object($value)) {
            return $value;
        }

        return match (true) {
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => $this->unwrap($value->jsonSerialize()),
            $value instanceof \UnitEnum => $value instanceof \BackedEnum ? $value->value : $value->name,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            default => get_object_vars($value),
        };
    }

    /**
     * Known secret values, cPanel session tokens, JSON documents and `name=value` pairs (query strings, form bodies,
     * header-like text) inside a string.
     *
     * @param  list<string>  $variants
     * @param  list<string>  $sensitiveKeys
     */
    private function maskString(string $value, array $variants, array $sensitiveKeys, int $depth): string
    {
        $trimmed = ltrim($value);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && $depth < self::MAX_DEPTH) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return (string) json_encode($this->redactWith($decoded, $variants, $sensitiveKeys, $depth + 1), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if ($variants !== []) {
            $value = str_replace($variants, self::MASK, $value);
        }

        $value = (string) preg_replace(self::SENSITIVE_VALUE_PATTERNS, self::MASK, $value);

        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_.\-\[\]])([A-Za-z][A-Za-z0-9_.\-\[\]]{0,63})(\s*[=:]\s*)(?!\/\/)((?:(?:Bearer|Basic|Digest|Token|whm)\s+)?(?:"[^"]*"|[^\s&;,"]+))/i',
            fn (array $match): string => $this->isSensitiveKey(rtrim($match[1], '[]'), $sensitiveKeys) ? $match[1].$match[2].self::MASK : $match[0],
            $value,
        );
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

    /**
     * Each secret plus the encoded forms it commonly appears in (URL-encoded, base64, Basic auth), longest first
     * so a longer encoding is masked before a substring of it.
     *
     * @param  list<string>  $secrets
     * @return list<string>
     */
    private function variants(array $secrets): array
    {
        $variants = [];

        foreach ($secrets as $secret) {
            if (strlen($secret) < 3) {
                continue;
            }

            array_push($variants, $secret, urlencode($secret), rawurlencode($secret), base64_encode($secret), rtrim(base64_encode($secret), '='));
        }

        $variants = array_values(array_unique(array_filter($variants, fn (string $variant): bool => strlen($variant) >= 3)));
        usort($variants, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $variants;
    }
}
