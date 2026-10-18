<?php

namespace App\Data\Currencies;

/**
 * Result of a dry run of a PriceConverter: how many rows would change and a sample of them.
 */
final readonly class PriceConversionPreview
{
    /**
     * @param  list<PriceChange>  $samples
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $changes,
        public int $unchanged,
        /** Rows staff set manually; conversion skips them. */
        public int $manual,
        public array $samples,
        public bool $automatic = false,
        /** Settings section with the module's automatic-conversion setting. */
        public ?string $settingsSection = null,
    ) {}

    /**
     * @return array{key: string, label: string, changes: int, unchanged: int, manual: int, automatic: bool, settings_section: string|null, samples: list<array{item: string, currency: string, period: string, before: int|null, after: int}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'changes' => $this->changes,
            'unchanged' => $this->unchanged,
            'manual' => $this->manual,
            'automatic' => $this->automatic,
            'settings_section' => $this->settingsSection,
            'samples' => array_map(fn (PriceChange $change): array => $change->toArray(), $this->samples),
        ];
    }
}
