<?php

namespace App\Modules\Services\Support;

/**
 * Removes secrets from module log payloads: sensitive keys and any known credential value.
 */
class Redactor
{
    private const MASK = '***';

    private const SENSITIVE_KEYS = '/password|passwd|secret|token|api_?key|hash|authorization/i';

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $secrets  values to mask wherever they appear
     * @return array<array-key, mixed>
     */
    public function redact(array $payload, array $secrets = []): array
    {
        $variants = $this->variants($secrets);
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEYS, $key) === 1 && $value !== null) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redact($value, $secrets),
                is_string($value) => $variants === [] ? $value : str_replace($variants, self::MASK, $value),
                default => $value,
            };
        }

        return $redacted;
    }

    /**
     * @param  list<string>  $secrets
     */
    public function maskValues(string $value, array $secrets): string
    {
        $secrets = $this->variants($secrets);

        return $secrets === [] ? $value : str_replace($secrets, self::MASK, $value);
    }

    /**
     * Each secret plus the encoded forms it commonly appears in (URL-encoded, base64, Basic auth),
     * longest first so a longer encoding is masked before a substring of it.
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
