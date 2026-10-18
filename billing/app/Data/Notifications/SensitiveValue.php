<?php

namespace App\Data\Notifications;

use JsonSerializable;
use Stringable;

/**
 * A merge-field value that grants access by itself — a one-time reset or verification link, an invitation link, a
 * guest ticket link with its access key. Merge-field resolvers wrap such values instead of returning strings, so
 * sensitivity is declared by type, not guessed from field names (security review PR #28).
 *
 * Staff-editable template text can never use one: Notifications removes every SensitiveValue from the context that
 * template text renders against, rejects templates that reference one, and only the system places the link, as its
 * own action button added after the mail is built. It is never put in webhook payloads and never printed by
 * accident: it has no string form beyond a mask.
 *
 *   'url' => $ticket->isGuest() ? new SensitiveValue($url) : $url,
 */
final readonly class SensitiveValue implements JsonSerializable, Stringable
{
    public function __construct(#[\SensitiveParameter] public string $value) {}

    public function __toString(): string
    {
        return '••••';
    }

    /**
     * Logs, JSON payloads and debug output never see the value.
     */
    public function jsonSerialize(): string
    {
        return '••••';
    }
}
