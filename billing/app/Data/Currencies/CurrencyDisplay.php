<?php

namespace App\Data\Currencies;

/**
 * How amounts in one currency are shown (Admin → Currencies): prefix, suffix, decimals and number format.
 * Shared with the browser as the `currencies` Inertia prop so server and UI format identically.
 */
final readonly class CurrencyDisplay
{
    public function __construct(
        public string $code,
        public string $prefix = '',
        public string $suffix = '',
        public int $decimals = 2,
        /** One of App\Support\Currencies\MoneyFormatter::FORMATS, e.g. "1,234.56" or "1,23,456.78". */
        public string $format = '1,234.56',
    ) {}

    /**
     * @return array{code: string, prefix: string, suffix: string, decimals: int, format: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'decimals' => $this->decimals,
            'format' => $this->format,
        ];
    }
}
