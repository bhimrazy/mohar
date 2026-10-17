<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\GatewayLog;

/**
 * Writes gateway_log rows with sensitive values redacted.
 */
class GatewayLogger
{
    private const SENSITIVE = '/card|pan|number|cvc|cvv|token|secret|signature|password|exp/i';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(string $gateway, string $direction, string $result, array $payload = [], ?int $invoiceId = null): GatewayLog
    {
        return GatewayLog::query()->create([
            'gateway' => $gateway,
            'direction' => $direction,
            'invoice_id' => $invoiceId,
            'result' => mb_substr($result, 0, 100),
            'payload' => self::cap(self::redact($payload)),
        ]);
    }

    /** Maximum stored payload size; larger payloads keep only a fingerprint. */
    public const MAX_PAYLOAD_BYTES = 8192;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function cap(array $payload): array
    {
        $json = (string) json_encode($payload);

        if (strlen($json) <= self::MAX_PAYLOAD_BYTES) {
            return $payload;
        }

        return ['truncated' => true, 'length' => strlen($json), 'sha256' => hash('sha256', $json), 'keys' => array_slice(array_map('strval', array_keys($payload)), 0, 50)];
    }

    /**
     * Mask card-number-like digit runs (13–19 digits, Luhn-valid) inside free text such as provider messages.
     */
    public static function scrubCardNumbers(string $value): string
    {
        return (string) preg_replace_callback('/\b(?:\d[ -]?){12,18}\d\b/', function (array $match): string {
            $digits = (string) preg_replace('/\D/', '', $match[0]);

            return self::luhn($digits) ? '[redacted-pan]' : $match[0];
        }, $value);
    }

    private static function luhn(string $digits): bool
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
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::redact($value);
            } elseif (is_string($key) && $key !== 'txn_id' && $key !== 'parent_txn_id' && preg_match(self::SENSITIVE, $key) === 1) {
                $payload[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $payload[$key] = self::scrubCardNumbers($value);
            }
        }

        return $payload;
    }
}
