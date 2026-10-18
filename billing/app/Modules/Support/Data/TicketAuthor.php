<?php

namespace App\Modules\Support\Data;

use App\Models\User;
use App\Modules\Support\Enums\AuthorType;

/**
 * Who wrote a message or performed an action on a ticket.
 */
final readonly class TicketAuthor
{
    public function __construct(
        public AuthorType $type,
        public string $name,
        public ?string $email = null,
        public ?User $user = null,
        public ?int $contactId = null,
    ) {}

    public static function staff(User $user): self
    {
        return new self(AuthorType::Staff, $user->name, $user->email, $user);
    }

    public static function client(User $user): self
    {
        return new self(AuthorType::Client, $user->name, $user->email, $user);
    }

    public static function guest(string $name, string $email): self
    {
        return new self(AuthorType::Guest, $name, $email);
    }

    public static function emailSender(string $name, string $email, bool $isClient, ?int $contactId = null): self
    {
        return new self($isClient ? AuthorType::Client : AuthorType::Guest, $name, $email, null, $contactId);
    }

    public static function system(): self
    {
        return new self(AuthorType::System, 'System');
    }

    public function isStaff(): bool
    {
        return $this->type === AuthorType::Staff;
    }
}
