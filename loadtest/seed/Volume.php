<?php

declare(strict_types=1);

namespace LoadTest\Seed;

use InvalidArgumentException;

/**
 * How much data to generate. `large` is the owner's target host: 50,000 clients and 100,000 services with a year
 * of invoices, payments and tickets. `tiny` is for a quick local check on SQLite.
 */
final class Volume
{
    public const PRESETS = [
        'tiny' => [200, 400, 2],
        'small' => [5_000, 10_000, 5],
        'medium' => [20_000, 40_000, 10],
        'large' => [50_000, 100_000, 20],
    ];

    public function __construct(
        public readonly string $name,
        public readonly int $clients,
        public readonly int $services,
        public readonly int $staff,
    ) {
        if ($clients < 1 || $services < $clients || $staff < 1) {
            throw new InvalidArgumentException('Need at least one client, one staff user and one service per client.');
        }
    }

    public static function named(string $name, ?int $clients = null, ?int $services = null): self
    {
        if (! isset(self::PRESETS[$name])) {
            throw new InvalidArgumentException("Unknown volume '{$name}'. Choose one of: ".implode(', ', array_keys(self::PRESETS)).'.');
        }

        [$presetClients, $presetServices, $staff] = self::PRESETS[$name];

        return new self($name, $clients ?? $presetClients, $services ?? $presetServices, $staff);
    }
}
