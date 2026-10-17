<?php

namespace App\Data\Billing;

/**
 * A gateway's staff configuration as handed to drivers (drivers never load models).
 */
final readonly class GatewayConfig
{
    /**
     * @param  array<string, mixed>  $settings  driver settings (secrets included; never log or expose them)
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public array $settings = [],
        public bool $sandbox = false,
        public ?string $convertToCurrency = null,
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * @return list<string> configured secret values, for redaction checks
     */
    public function secretValues(): array
    {
        return array_values(array_filter(array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $this->settings), fn (string $value): bool => strlen($value) >= 8));
    }
}
