<?php

namespace App\Modules\Core\Support;

/**
 * List-valued settings may be stored as arrays (config defaults) or as comma/newline separated text
 * (edited through the generic settings UI). Normalise both to a list of trimmed strings.
 */
final class SettingList
{
    /**
     * @return list<string>
     */
    public static function of(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item): string => trim((string) $item), $value), fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
