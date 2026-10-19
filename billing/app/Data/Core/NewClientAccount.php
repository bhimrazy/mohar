<?php

namespace App\Data\Core;

use Carbon\CarbonInterface;

/**
 * Everything needed to open a client account with its owner login (ADR 0018 §6). A null password makes a
 * passwordless login (a sign-in provider's); an email-verified time marks the address as already verified. New fields
 * are only added at the end, with defaults (ADR 0010).
 *
 * @api
 */
final readonly class NewClientAccount
{
    /**
     * @param  ?string  $currency  null for the default currency
     * @param  bool  $announceRegistered  false when the caller dispatches Laravel's Registered itself (Fortify's
     *                                    register controller does)
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        #[\SensitiveParameter] public ?string $password = null,
        public ?CarbonInterface $emailVerifiedAt = null,
        public ?string $currency = null,
        public ?string $companyName = null,
        public ?string $country = null,
        public bool $announceRegistered = true,
    ) {}

    /**
     * Splits a full name the way sign-up does: the first word is the first name.
     */
    public static function fromName(string $name, string $email, ?string $password = null, ?CarbonInterface $emailVerifiedAt = null, bool $announceRegistered = true): self
    {
        $name = trim($name);
        $first = str_contains($name, ' ') ? strstr($name, ' ', true) : $name;

        return new self((string) $first, str_contains($name, ' ') ? trim((string) strstr($name, ' ')) : '', $email, $password, $emailVerifiedAt, announceRegistered: $announceRegistered);
    }
}
