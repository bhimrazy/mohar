<?php

namespace App\Modules\Billing\Support;

use App\Modules\Core\Support\Settings;

/**
 * Typed access to billing settings (DB override, else Config/billing.php default).
 */
class BillingSettings
{
    public function __construct(private Settings $settings) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings->get('billing.'.$key, $default);
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A list setting stored as an array or a comma-separated string ("3, 7").
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key, []);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''), $value), fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        return array_values(array_map('intval', array_filter($this->list($key), 'is_numeric')));
    }
}
