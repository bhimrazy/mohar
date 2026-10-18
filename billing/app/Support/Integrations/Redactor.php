<?php

namespace App\Support\Integrations;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Removes secrets from integration log payloads (gateway log, module log, registrar command log) and from messages
 * shown to staff: values under sensitive keys, card numbers, cPanel session tokens, and every known credential value
 * wherever it appears. The host redacts what drivers return; drivers never call this.
 *
 * Keys are split into words (`api_key`, `api-key`, `ApiKey` and `APIKey` all read as "api key"), and a key is
 * sensitive when one of its words, or a pair of adjacent words, names a secret. Matching whole words keeps
 * `author`, `country_code` and `keyword` visible.
 *
 * @api
 */
final class Redactor
{
    public const MASK = '[redacted]';

    public const CARD_MASK = '[redacted-pan]';

    /** Longest raw driver output kept for staff and logs, in bytes. */
    public const EXCERPT_BYTES = 4096;

    /** A key is sensitive when it is exactly one of these. */
    private const SENSITIVE_KEYS = ['key', 'pw', 'pwd'];

    /** ...or when one of its words is one of these. */
    private const SENSITIVE_WORDS = [
        'password', 'passwd', 'pass', 'passphrase', 'secret', 'token', 'auth', 'authorization', 'signature', 'hash',
        'session', 'cookie', 'epp', 'otp', 'apikey', 'cvc', 'cvv', 'cvv2', 'pan', 'card', 'exp', 'expiry',
    ];

    /** ...or when two adjacent words are one of these pairs. */
    private const SENSITIVE_PAIRS = [
        ['api', 'key'], ['private', 'key'], ['access', 'key'], ['license', 'key'], ['transfer', 'code'],
        ['auth', 'code'], ['card', 'number'], ['account', 'number'],
    ];

    /** Values that are secret wherever they appear, whatever the key (cPanel session tokens). */
    private const SENSITIVE_VALUE_PATTERNS = ['/cpsess\d+/'];

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
        return $this->redactWith($payload, $this->variants($secrets), array_map('strtolower', $sensitiveKeys));
    }

    /**
     * @param  list<string>  $secrets
     */
    public function maskValues(string $value, array $secrets): string
    {
        return $this->maskString($value, $this->variants($secrets));
    }

    /**
     * Raw driver output (a provider's response body or error text) made safe to show staff: JSON is redacted by key,
     * any text has known secrets masked, and the result is cut to $limit bytes.
     *
     * @param  list<string>  $secrets
     */
    public function excerpt(string $raw, array $secrets = [], int $limit = self::EXCERPT_BYTES): string
    {
        $decoded = json_decode($raw, true);
        $safe = is_array($decoded)
            ? (string) json_encode($this->redact($decoded, $secrets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->maskValues($raw, $secrets);

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

        if (in_array($lower, self::SENSITIVE_KEYS, true) || in_array($lower, $sensitiveKeys, true)) {
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

        // Run-together spellings (`newpassword`, `x_apitoken`) still count for the unambiguous long words.
        return preg_match('/password|passwd|secret|token|apikey|signature|authorization/', $lower) === 1;
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

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $variants
     * @param  list<string>  $sensitiveKeys
     * @return array<array-key, mixed>
     */
    private function redactWith(array $payload, array $variants, array $sensitiveKeys): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $value = $this->unwrap($value);

            if ($value !== null && $value !== '' && $value !== [] && $this->isSensitiveKey($key, $sensitiveKeys)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redactWith($value, $variants, $sensitiveKeys),
                is_string($value) => $this->maskString($value, $variants),
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
     * @param  list<string>  $variants
     */
    private function maskString(string $value, array $variants): string
    {
        if ($variants !== []) {
            $value = str_replace($variants, self::MASK, $value);
        }

        return $this->scrubCardNumbers((string) preg_replace(self::SENSITIVE_VALUE_PATTERNS, self::MASK, $value));
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
