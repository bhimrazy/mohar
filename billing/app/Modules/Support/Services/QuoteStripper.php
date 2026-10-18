<?php

namespace App\Modules\Support\Services;

/**
 * Removes quoted history from email replies (spec §6.9).
 */
class QuoteStripper
{
    /** Common "client quote header" lines that start the quoted part. */
    private const HEADERS = [
        '/^On .{3,200}wrote:\s*$/m',
        '/^-{2,}\s*Original Message\s*-{2,}\s*$/mi',
        '/^_{5,}\s*$/m',
        '/^From: .+\R(?:Sent|Date): .+$/m',
    ];

    public function strip(string $text, string $marker): string
    {
        $text = str_replace("\r\n", "\n", $text);

        if ($marker !== '' && ($position = mb_stripos($text, $marker)) !== false) {
            $text = mb_substr($text, 0, $position);
        }

        foreach (self::HEADERS as $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                $text = substr($text, 0, (int) $match[0][1]);
            }
        }

        $lines = explode("\n", rtrim($text));

        while ($lines !== [] && (str_starts_with(ltrim((string) end($lines)), '>') || trim((string) end($lines)) === '')) {
            array_pop($lines);
        }

        return trim(implode("\n", $lines));
    }
}
