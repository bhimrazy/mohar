<?php

namespace App\Modules\Domains\Support;

/**
 * Registrar ids (order id, transfer id) that later calls need, kept in registrar_meta.remote (registrars.md C1).
 * Only short scalar values are kept: a driver's result never writes arbitrary payloads into the domain row.
 */
final class RemoteIds
{
    private const MAX_KEY = 40;

    private const MAX_VALUE = 191;

    /**
     * @param  array<string, mixed>|null  $meta  current registrar_meta
     * @param  array<array-key, mixed>  $remote
     * @return array<string, mixed>|null
     */
    public static function merge(?array $meta, array $remote): ?array
    {
        $clean = [];

        foreach ($remote as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && strlen($key) <= self::MAX_KEY
                && (is_int($value) || (is_string($value) && $value !== '' && strlen($value) <= self::MAX_VALUE))) {
                $clean[$key] = $value;
            }
        }

        if ($clean === []) {
            return $meta;
        }

        $meta ??= [];
        $meta['remote'] = [...(is_array($meta['remote'] ?? null) ? $meta['remote'] : []), ...$clean];

        return $meta;
    }
}
