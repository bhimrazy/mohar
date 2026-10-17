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
            'payload' => self::redact($payload),
        ]);
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
            }
        }

        return $payload;
    }
}
