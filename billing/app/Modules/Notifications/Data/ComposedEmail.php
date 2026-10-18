<?php

namespace App\Modules\Notifications\Data;

/**
 * A rendered email: what the recipient gets, plus the redacted copy stored in the log.
 */
final readonly class ComposedEmail
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $subject,
        public ?string $html,
        public string $text,
        public ?string $storedHtml,
        public string $storedText,
        public bool $hasRedactions,
        public array $warnings = [],
    ) {}
}
