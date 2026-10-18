<?php

namespace App\Support\Integrations;

/**
 * Removes secrets from integration log payloads (module log, registrar command log) and from messages shown to
 * staff: values under sensitive keys, and every known credential value wherever it appears. The host redacts
 * what drivers return; drivers never call this.
 *
 * @api
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /** Longest raw driver output kept for staff and logs, in bytes. */
    public const EXCERPT_BYTES = 4096;

    /** A key is sensitive when it equals one of these (case-insensitive). */
    private const SENSITIVE_KEYS = ['code', 'pw', 'key', 'pass'];

    /** A key is sensitive when it contains one of these (case-insensitive). */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'api-key', 'private_key', 'hash', 'auth',
        'signature', 'epp', 'transfer_code', 'session', 'cp_security_token',
    ];

    /** Values that are secret wherever they appear, whatever the key (cPanel session tokens). */
    private const SENSITIVE_VALUE_PATTERNS = ['/cpsess\d+/'];

    /**
     * A sensitive key hides its whole value, nested arrays included. Null and empty values are kept, so the log
     * still shows that nothing was sent.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $secrets  values to mask wherever they appear
     * @param  list<string>  $sensitiveKeys  extra keys that are secret in this payload only, e.g. `url` in an SSO answer
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

        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_KEYS, true) || in_array($key, $sensitiveKeys, true)) {
            return true;
        }

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param  list<string>  $variants
     */
    private function maskString(string $value, array $variants): string
    {
        if ($variants !== []) {
            $value = str_replace($variants, self::MASK, $value);
        }

        return (string) preg_replace(self::SENSITIVE_VALUE_PATTERNS, self::MASK, $value);
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
