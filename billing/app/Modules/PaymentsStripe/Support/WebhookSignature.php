<?php

namespace App\Modules\PaymentsStripe\Support;

/**
 * Stripe-Signature verification (docs.stripe.com/webhooks, "Verify webhook signatures manually"): the header holds
 * t=<unix time> and one or more v1=<hex HMAC-SHA256 of "t.body">. Other schemes (v0 test signatures) are ignored
 * so they can't be used for a downgrade.
 */
final class WebhookSignature
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const STALE = 'stale';

    /**
     * @return array{0: self::VALID|self::INVALID|self::STALE, 1: int|null} outcome and the signed timestamp
     */
    public static function verify(string $header, string $payload, string $secret, int $toleranceSeconds, ?int $now = null): array
    {
        if ($secret === '' || $header === '' || strlen($header) > 4096) {
            return [self::INVALID, null];
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $element) {
            [$scheme, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            if ($scheme === 't' && ctype_digit($value) && strlen($value) <= 12) {
                $timestamp = (int) $value;
            } elseif ($scheme === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return [self::INVALID, null];
        }

        $expected = self::sign($payload, $secret, $timestamp);
        $matched = false;

        foreach ($signatures as $signature) {
            // Every candidate is compared, so the timing doesn't reveal which one matched.
            $matched = hash_equals($expected, $signature) || $matched;
        }

        if (! $matched) {
            return [self::INVALID, $timestamp];
        }

        return abs(($now ?? time()) - $timestamp) > $toleranceSeconds ? [self::STALE, $timestamp] : [self::VALID, $timestamp];
    }

    /** Lowercase hex HMAC-SHA256 over "<timestamp>.<payload>", as Stripe signs. */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /** A header as Stripe sends it (tests and the smoke command). */
    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::sign($payload, $secret, $timestamp);
    }
}
