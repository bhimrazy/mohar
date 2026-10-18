<?php

namespace App\Data\Integrations;

/**
 * A provider's official logo, served from this site (never hot-linked). $url suits light backgrounds; $darkUrl,
 * when the provider publishes one, suits dark backgrounds. $alt is what a screen reader announces, e.g.
 * "Pay with Khalti".
 */
final readonly class IntegrationLogo
{
    public function __construct(
        public string $url,
        public string $alt,
        public ?string $darkUrl = null,
    ) {}

    /**
     * @return array{url: string, dark_url: string|null, alt: string}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'dark_url' => $this->darkUrl, 'alt' => $this->alt];
    }
}
