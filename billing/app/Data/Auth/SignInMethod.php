<?php

namespace App\Data\Auth;

use App\Data\Integrations\IntegrationLogo;

/**
 * A way to sign in other than a password or a passkey (both native Fortify features), such as a sign-in provider
 * (ADR 0018 §1). New fields are only added at the end, with defaults (ADR 0010).
 *
 * @api
 */
final readonly class SignInMethod
{
    /**
     * @param  string  $startUrl  where the button sends the browser to begin
     * @param  bool  $staff  also offered to staff
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $startUrl,
        public bool $staff = false,
        public ?IntegrationLogo $logo = null,
    ) {}

    /**
     * @return array{key: string, label: string, start_url: string, staff: bool, logo: array{url: string, dark_url: string|null, alt: string}|null}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'start_url' => $this->startUrl, 'staff' => $this->staff, 'logo' => $this->logo?->toArray()];
    }
}
