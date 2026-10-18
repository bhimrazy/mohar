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

    /** A key is sensitive when it equals one of these (case-insensitive). */
    private const SENSITIVE_KEYS = ['code', 'pw', 'key', 'pass'];

    /** A key is sensitive when it contains one of these (case-insensitive). */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'api-key', 'private_key', 'hash', 'auth',
        'signature', 'epp', 'transfer_code',
    ];

    /**
     * A sensitive key hides its whole value, nested arrays included. Null and empty values are kept, so the log
     * still shows that nothing was sent.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $secrets  values to mask wherever they appear
     * @return array<array-key, mixed>
     */
    public function redact(array $payload, array $secrets = []): array
    {
        return $this->redactWith($payload, $this->variants($secrets));
    }

    /**
     * @param  list<string>  $secrets
     */
    public function maskValues(string $value, array $secrets): string
    {
        $variants = $this->variants($secrets);

        return $variants === [] ? $value : str_replace($variants, self::MASK, $value);
    }

    public function isSensitiveKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
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
     * @return array<array-key, mixed>
     */
    private function redactWith(array $payload, array $variants): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if ($value !== null && $value !== '' && $value !== [] && $this->isSensitiveKey($key)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redactWith($value, $variants),
                is_string($value) && $variants !== [] => str_replace($variants, self::MASK, $value),
                default => $value,
            };
        }

        return $redacted;
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
