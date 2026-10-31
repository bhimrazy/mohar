<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\GatewayLog;
use App\Support\Integrations\RedactionScope;
use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;

/**
 * Writes gateway_log rows with sensitive values redacted (App\Support\Integrations\Redactor), the gateway's own
 * configured credentials included.
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
            'payload' => self::cap(self::redact($payload, $gateway)),
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
     * Gateway payloads go through the kernel payment rule (App\Support\Integrations\Redactor::redactPayment()), with
     * $gateway's configured credentials masked by value. Every stored copy of a provider payload (gateway_logs, and
     * transaction, refund and attempt `raw`) passes its gateway's key.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload, string $gateway): array
    {
        return app(Redactor::class)->redactPayment($payload, secrets: self::secrets($gateway));
    }

    /**
     * The values masked in $gateway's payloads: its settings typed Secret or SecretText (GatewayConfig::secrets()),
     * and the sets of the calls in progress (RedactionScope).
     */
    public static function secrets(string $gateway): RedactionSet
    {
        $gateways = app(GatewayManager::class);

        return RedactionSet::merge(
            RedactionSet::of($gateways->has($gateway) ? $gateways->config($gateway)->secrets() : []),
            RedactionScope::current(),
        );
    }
}
