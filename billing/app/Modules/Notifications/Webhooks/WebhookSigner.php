<?php

namespace App\Modules\Notifications\Webhooks;

/**
 * X-Webhook-Signature: v1=<hex HMAC-SHA256(secret, "{timestamp}.{raw_body}")>, one v1 value per valid
 * secret (two during a rotation), comma-separated (spec §8).
 */
final class WebhookSigner
{
    /**
     * @param  list<string>  $secrets
     */
    public static function header(array $secrets, int $timestamp, string $body): string
    {
        return implode(',', array_map(fn (string $secret): string => 'v1='.self::sign($secret, $timestamp, $body), $secrets));
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
