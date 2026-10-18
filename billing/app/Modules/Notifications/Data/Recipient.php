<?php

namespace App\Modules\Notifications\Data;

/**
 * One resolved email recipient. Exactly one of userId/contactId/staffId is usually set; the account's
 * primary address (owner) carries only clientId.
 */
final readonly class Recipient
{
    public function __construct(
        public string $email,
        public string $name,
        public ?string $locale = null,
        public ?int $clientId = null,
        public ?int $userId = null,
        public ?int $contactId = null,
        public ?int $staffId = null,
    ) {}

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0] ?: $this->name;
    }

    public function isStaff(): bool
    {
        return $this->staffId !== null;
    }

    public function key(): string
    {
        return mb_strtolower($this->email);
    }
}
