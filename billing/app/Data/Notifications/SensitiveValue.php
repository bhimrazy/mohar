<?php

namespace App\Data\Notifications;

use JsonSerializable;
use Stringable;

/**
 * A merge-field value that grants access by itself — a one-time reset or verification link, an invitation link, a
 * guest ticket link with its access key. Merge-field resolvers wrap such values instead of returning strings, so
 * sensitivity is declared by type, not guessed from field names (security review PR #28).
 *
 * Notifications removes a SensitiveValue from every template's context, except templates matching $allowedIn
 * (system template keys, `*` wildcards; custom templates never match), where it may only be the whole target of a
 * link — anywhere else it is masked. It is never put in webhook payloads and never printed by accident: it has no
 * string form beyond a mask.
 *
 *   'url' => $ticket->isGuest() ? new SensitiveValue($url, allowedIn: ['ticket.*']) : $url,
 */
final readonly class SensitiveValue implements JsonSerializable, Stringable
{
    /**
     * @param  list<string>  $allowedIn  template keys (e.g. "ticket.*") that may link to this value
     */
    public function __construct(
        #[\SensitiveParameter] public string $value,
        public array $allowedIn = [],
    ) {}

    public function allowedIn(string $templateKey): bool
    {
        if (str_starts_with($templateKey, 'custom.') || $templateKey === 'adhoc') {
            return false;
        }

        foreach ($this->allowedIn as $pattern) {
            if ($pattern === $templateKey || (str_ends_with($pattern, '.*') && str_starts_with($templateKey, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

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
