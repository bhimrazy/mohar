<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\GatewayLog;
use App\Support\Integrations\Redactor;

/**
 * Writes gateway_log rows with sensitive values redacted (App\Support\Integrations\Redactor).
 */
class GatewayLogger
{
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
     * Gateway payloads are redacted with the kernel rules shared by every integration log (card numbers, card and
     * secret keys, known credential values).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        return app(Redactor::class)->redact($payload);
    }
}
