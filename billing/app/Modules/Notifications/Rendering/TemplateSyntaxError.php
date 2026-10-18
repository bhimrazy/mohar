<?php

namespace App\Modules\Notifications\Rendering;

use RuntimeException;

/**
 * A template that cannot be parsed (unclosed block, unknown tag or filter, malformed placeholder).
 * Saving is blocked; at send time the message is marked failed (spec §3).
 */
class TemplateSyntaxError extends RuntimeException
{
    public static function at(int $line, string $message): self
    {
        return new self("Line {$line}: {$message}");
    }
}
