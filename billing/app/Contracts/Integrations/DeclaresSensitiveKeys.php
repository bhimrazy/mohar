<?php

namespace App\Contracts\Integrations;

/**
 * Capability (ADR 0012, ADR 0023 §5) for any integration driver whose payloads carry a secret under a key the
 * Redactor's name rule does not read as one: `code` in an EPP code answer, `url` in a single sign-on answer. The host
 * passes the keys to Redactor::redact() for that operation's request and response. There is no global key registry.
 */
interface DeclaresSensitiveKeys
{
    /**
     * Exact keys, any case, that are secret in the payloads of $operation (the host's command or capability name).
     *
     * @return list<string>
     */
    public function sensitiveKeys(string $operation): array;
}
