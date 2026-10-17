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
        $secrets = array_values(array_filter($secrets, fn (string $secret): bool => strlen($secret) >= 3));

        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEYS, $key) === 1 && $value !== null && ! is_array($value)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redact($value, $secrets),
                is_string($value) => $this->maskValues($value, $secrets),
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
        return $secrets === [] ? $value : str_replace($secrets, self::MASK, $value);
    }
}
