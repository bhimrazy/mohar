<?php

namespace App\Support\Dashboard;

/**
 * Trend pills for dashboard stat cards: ['value' => '12.1%', 'direction' => 'up', 'upIsGood' => true].
 */
final class Trend
{
    /**
     * Percentage change from $previous to $current; null when there is nothing to compare against.
     *
     * @return array{value: string, direction: string, upIsGood: bool}|null
     */
    public static function percent(int $current, int $previous, bool $upIsGood = true): ?array
    {
        if ($previous === 0) {
            return null;
        }

        $change = ($current - $previous) / abs($previous) * 100;
        $rounded = round(abs($change), 1);

        return [
            'value' => ($rounded >= 100 ? (string) round($rounded) : number_format($rounded, 1)).'%',
            'direction' => $rounded === 0.0 ? 'flat' : ($change > 0 ? 'up' : 'down'),
            'upIsGood' => $upIsGood,
        ];
    }

    /**
     * Absolute change in a count, e.g. "+3".
     *
     * @return array{value: string, direction: string, upIsGood: bool}
     */
    public static function delta(int $current, int $previous, bool $upIsGood = true): array
    {
        $change = $current - $previous;

        return [
            'value' => ($change > 0 ? '+' : ($change < 0 ? '−' : '')).abs($change),
            'direction' => $change === 0 ? 'flat' : ($change > 0 ? 'up' : 'down'),
            'upIsGood' => $upIsGood,
        ];
    }
}
