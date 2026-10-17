<?php

namespace App\Modules\Catalog\Support;

/**
 * Payment-gateway restrictions of product groups. `null` means "every gateway".
 */
final class Gateways
{
    /**
     * Gateways allowed for a cart mixing these groups: the intersection of their lists (null = unrestricted).
     *
     * @param  list<list<string>|null>  $groupGateways
     * @return list<string>|null
     */
    public static function intersection(array $groupGateways): ?array
    {
        $result = null;

        foreach ($groupGateways as $gateways) {
            if ($gateways === null) {
                continue;
            }

            $result = $result === null ? array_values(array_unique($gateways)) : array_values(array_intersect($result, $gateways));
        }

        return $result;
    }

    /**
     * Pairs of groups (by name) that share no gateway, so a cart containing both could not be paid.
     *
     * @param  array<string, list<string>|null>  $groups  name => allowed gateways
     * @return list<array{0: string, 1: string}>
     */
    public static function conflicts(array $groups): array
    {
        $restricted = array_filter($groups, fn (?array $gateways): bool => $gateways !== null);
        $names = array_keys($restricted);
        $conflicts = [];

        foreach ($names as $i => $first) {
            foreach (array_slice($names, $i + 1) as $second) {
                if (array_intersect($restricted[$first], $restricted[$second]) === []) {
                    $conflicts[] = [(string) $first, (string) $second];
                }
            }
        }

        return $conflicts;
    }
}
