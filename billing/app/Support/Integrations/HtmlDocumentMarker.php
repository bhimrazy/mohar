<?php

namespace App\Support\Integrations;

/**
 * Finds whether one text is an HTML document before a given offset, for Redactor::scan(): a secret void-named element
 * (`<input name="password">`) keeps its next-tag bound only after an HTML document marker, and fails closed anywhere
 * else. A marker is a `<!doctype html` or an unprefixed `<html` start tag, any case, at markup level. Spans the scan
 * masks are skipped, so a marker inside a masked value does not count.
 *
 * One forward walk over the text for all calls, with strpos and strspn and constant extra memory, so the cost is
 * linear however many elements ask.
 *
 * Also holds the markup name rule that this walk and Redactor::voidContainerEnd() share. The Redactor's text pass
 * (TOKEN_PATTERN) reads ASCII names only, on purpose: a non-ASCII `<…` stays text there, so the pairs in it are scanned.
 *
 * @internal
 */
final class HtmlDocumentMarker
{
    private const ASCII_NAME_START = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_:';

    /** Where the walk resumes; null when the text holds no marker text or the walk has reached its end. */
    private ?int $from;

    private bool $seen = false;

    public function __construct(private readonly string $text)
    {
        $this->from = stripos($text, '<html') === false && stripos($text, '<!doctype') === false ? null : 0;
    }

    /**
     * The length of the markup name at $offset, or 0 when no name starts there. A name starts with an ASCII letter, `_`,
     * `:` or a byte from 0x80 (UTF-8), and goes on with those, digits, `.` and `-`. Bytes are listed, not read through
     * ctype_*(), which follows the locale.
     */
    public static function nameLength(string $value, int $offset): int
    {
        return strspn($value, self::nameStartBytes(), $offset, 1) === 0 ? 0 : strspn($value, self::nameBytes(), $offset);
    }

    /**
     * Whether a marker starts before $offset, outside every span skipped so far. Once true, it stays true. It moves the
     * walk on, so a later call resumes where this one stopped.
     *
     * @phpstan-impure
     */
    public function before(int $offset): bool
    {
        $this->seen = $this->seen || ($this->from !== null && $this->next($offset) !== null);

        return $this->seen;
    }

    /** The scan masked $from to $to: the walk jumps over it, so a marker inside is never reached. */
    public function skip(int $from, int $to): void
    {
        if ($this->from === null || $this->seen) {
            return;
        }

        $this->before($from);

        if ($this->from !== null && $this->from < $to) {
            $this->from = $to;
        }
    }

    /**
     * The next marker that starts before $before, or null. Comments, CDATA sections, processing instructions and other
     * declarations are skipped whole, and a tag's attributes with quoted values honoured, so a marker inside them is
     * not found. An unterminated construct runs to the end of the text. `<head` and `<body` are not markers (SOAP has
     * a `<Body>`). The walk stops at the `<` it reached at $before, so the next call does not rescan.
     */
    private function next(int $before): ?int
    {
        $value = $this->text;
        $length = strlen($value);
        $position = (int) $this->from;

        while (($open = strpos($value, '<', $position)) !== false) {
            if ($open >= $before) {
                $this->from = $open;

                return null;
            }

            $next = $value[$open + 1] ?? '';

            if ($next === '!' || $next === '?') {
                if (substr_compare($value, '<!--', $open, 4) === 0) {
                    $close = strpos($value, '-->', $open + 4);
                    $position = $close === false ? $length : $close + 3;
                } elseif (substr_compare($value, '<![CDATA[', $open, 9) === 0) {
                    $close = strpos($value, ']]>', $open + 9);
                    $position = $close === false ? $length : $close + 3;
                } elseif ($next === '?') {
                    $close = strpos($value, '?>', $open + 2);
                    $position = $close === false ? $length : $close + 2;
                } else {
                    $spaces = strspn($value, " \t\r\n\f", $open + 9);

                    if (substr_compare($value, '<!doctype', $open, 9, true) === 0 && $spaces > 0
                        && substr_compare($value, 'html', $open + 9 + $spaces, 4, true) === 0
                        && strspn($value, self::nameBytes(), $open + 13 + $spaces, 1) === 0) {
                        $this->from = $this->skipTag($open + 2);

                        return $open;
                    }

                    $position = $this->skipTag($open + 2);
                }

                continue;
            }

            // A byte from 0x80 starts a name, so `<é t="<html>">` is a tag, not text (#309).
            $nameLength = self::nameLength($value, $open + 1);

            if ($nameLength === 0 && $next !== '/') {
                $position = $open + 1; // a `<` that starts no tag is text

                continue;
            }

            $position = $this->skipTag($open + 1 + $nameLength);

            if ($nameLength === 4 && strcasecmp(substr($value, $open + 1, 4), 'html') === 0) {
                $this->from = $position;

                return $open;
            }
        }

        $this->from = null;

        return null;
    }

    /** The offset after the `>` that ends the tag whose attributes start at $position; quoted values may hold `>`. An unterminated tag or quote runs to the end. */
    private function skipTag(int $position): int
    {
        $value = $this->text;
        $length = strlen($value);

        while (true) {
            $at = $position + strcspn($value, '>"\'', $position);

            if ($at >= $length) {
                return $length;
            }

            if ($value[$at] === '>') {
                return $at + 1;
            }

            $close = strpos($value, $value[$at], $at + 1);

            if ($close === false) {
                return $length;
            }

            $position = $close + 1;
        }
    }

    private static function nameStartBytes(): string
    {
        static $bytes = null;

        return $bytes ??= self::ASCII_NAME_START.self::highBytes();
    }

    private static function nameBytes(): string
    {
        static $bytes = null;

        return $bytes ??= self::ASCII_NAME_START.'0123456789.-'.self::highBytes();
    }

    /** Bytes 0x80 to 0xFF, which UTF-8 names are made of. */
    private static function highBytes(): string
    {
        return implode('', array_map('chr', range(0x80, 0xFF)));
    }
}
