<?php

namespace App\Support\Integrations;

/**
 * The secret values of one call (configured credentials and request secrets) and the forms they are matched in
 * (ADR 0023 §2). The forms are built once, when the set is made, and reused for every string it masks.
 *
 * Each value is trimmed, then matched as a substring in these forms:
 * - as typed, urlencode, rawurlencode, JSON-escaped and HTML-escaped, case-insensitively (ASCII case, on bytes);
 * - base64 and base64url, case-sensitively: the whole encoding with and without padding, and the core that stays the
 *   same whatever bytes sit before or after it (0, 1 or 2 prefix bytes, the dependent edge characters dropped), built
 *   only when that core is 8 characters or more, since a shorter one would match inside ordinary words;
 * - hex in either case, for values of 8 bytes or more;
 * - entity and percent spellings: a word (a run without white space) holding `&` or `%` is decoded until stable (at
 *   most 6 passes) and masked whole when its decoded form holds a value;
 * - truncated echoes, for values of 16 bytes or more: from the value's first 8 bytes to the end of that run of token
 *   characters, and from the start of the run to the value's last 8 bytes. An anchor holding a non-token character,
 *   or one that is a known key-shape prefix (`sk_live_`), is not built.
 * A value holding a line break (a SecretText PEM or SSH key) also matches each of its lines of 16 bytes or more.
 *
 * Bounds (ADR 0023 Consequences): values under 4 bytes are not matched at all, truncated echoes under 8 bytes stay
 * readable, and the offset base64 cores exist only for values of about 7 bytes or more. Values are not regrouped.
 *
 * The cost is the text's length times the number of forms (about 20 per value), never the input squared.
 *
 * @api
 */
final class RedactionSet
{
    /** Shortest value matched, in bytes. A shorter one is masked only by its key or label. */
    public const MIN_MATCH_BYTES = 4;

    /** Shortest hex form: a short value's hex is a plain digit run. */
    private const MIN_HEX_BYTES = 8;

    /** Shortest base64 core built at a shifted alignment, in characters. */
    private const MIN_BASE64_CORE = 8;

    /** Values from this length also mask truncated echoes; the anchors are this many bytes long. */
    private const TRUNCATED_FROM_BYTES = 16;

    private const ANCHOR_BYTES = 8;

    /** Shortest line of a multi-line value matched on its own. */
    private const MIN_LINE_BYTES = 16;

    /** Characters a token run is made of (base64, base64url, hex and the usual key alphabets). */
    private const TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_+/=';

    /** Prefixes the shape rules already mask; an anchor that is one of them (or the start of one) would add nothing. */
    private const SHAPE_PREFIXES = ['sk_live_', 'sk_test_', 'rk_live_', 'rk_test_', 'whsec_', 'live_secret_key_', 'test_secret_key_'];

    private const MAX_DECODE_PASSES = 6;

    private const WHITE_SPACE = " \t\r\n\f\v";

    /** @var list<string> lowercased values, for the decoded-word check */
    private array $values = [];

    /** @var list<string> lowercased forms matched case-insensitively */
    private array $foldedForms = [];

    /** @var list<string> forms matched exactly */
    private array $exactForms = [];

    /** @var list<string> lowercased first-8-byte anchors */
    private array $startAnchors = [];

    /** @var list<string> lowercased last-8-byte anchors */
    private array $endAnchors = [];

    /**
     * @param  list<string>  $values
     */
    private function __construct(array $values)
    {
        foreach ($values as $value) {
            $this->add($value);

            if (strpbrk($value, "\r\n") !== false) {
                foreach (preg_split('/\R/', $value) ?: [] as $line) {
                    if (strlen(trim($line)) >= self::MIN_LINE_BYTES) {
                        $this->add($line);
                    }
                }
            }
        }

        $this->values = array_values(array_unique($this->values));
        $this->foldedForms = array_values(array_unique($this->foldedForms));
        $this->exactForms = array_values(array_unique(array_diff($this->exactForms, $this->foldedForms)));
        $this->startAnchors = array_values(array_unique($this->startAnchors));
        $this->endAnchors = array_values(array_unique($this->endAnchors));
    }

    /**
     * @param  list<string>  $values
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Every match of a value in $text replaced by $mask. Overlapping and adjacent matches become one mask.
     */
    public function mask(string $text, string $mask = Redactor::MASK): string
    {
        if ($this->values === [] || $text === '') {
            return $text;
        }

        $folded = strtolower($text);
        $spans = [];

        foreach ($this->foldedForms as $form) {
            $this->collect($spans, $folded, $form);
        }

        foreach ($this->exactForms as $form) {
            $this->collect($spans, $text, $form);
        }

        foreach ($this->startAnchors as $anchor) {
            $at = strpos($folded, $anchor);

            while ($at !== false) {
                $end = $at + self::ANCHOR_BYTES;
                $end += strspn($text, self::TOKEN_CHARS, $end);
                $spans[] = [$at, $end];
                $at = strpos($folded, $anchor, $end);
            }
        }

        foreach ($this->endAnchors as $anchor) {
            // A backward walk never goes below the previous span's end, so each byte is walked once per anchor.
            $floor = 0;

            for ($at = strpos($folded, $anchor); $at !== false; $at = strpos($folded, $anchor, $at + 1)) {
                $start = $at;

                while ($start > $floor && str_contains(self::TOKEN_CHARS, $text[$start - 1])) {
                    $start--;
                }

                $spans[] = [$start, $floor = $at + self::ANCHOR_BYTES];
            }
        }

        return $this->maskDecodedWords($this->apply($text, $spans, $mask), $mask);
    }

    /**
     * Whether $text holds a value in any form (a key or a numeric leaf the caller withholds whole).
     */
    public function matches(string $text): bool
    {
        return $this->mask($text, "\0") !== $text;
    }

    /**
     * The one decoder for finding a secret in mail and logs: HTML entities and percent-encoding decoded until nothing
     * changes (so partial and double encodings are caught), at most 6 passes, then lower case.
     */
    public static function normalise(string $text): string
    {
        return mb_strtolower(self::decode($text));
    }

    private static function decode(string $text): string
    {
        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            $decoded = rawurldecode(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($decoded === $text) {
                break;
            }

            $text = $decoded;
        }

        return $text;
    }

    private function add(string $value): void
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length < self::MIN_MATCH_BYTES) {
            return;
        }

        $this->values[] = strtolower($value);

        $folded = [
            $value,
            urlencode($value),
            rawurlencode($value),
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401),
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5),
        ];

        foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $json = json_encode($value, $flags | JSON_INVALID_UTF8_SUBSTITUTE);

            if (is_string($json)) {
                $folded[] = substr($json, 1, -1);
            }
        }

        if ($length >= self::MIN_HEX_BYTES) {
            $folded[] = bin2hex($value);
        }

        foreach ($folded as $form) {
            $this->foldedForms[] = strtolower($form);
        }

        foreach ($this->base64Forms($value) as $form) {
            $this->exactForms[] = $form;
            $this->exactForms[] = strtr($form, '+/', '-_');
        }

        if ($length >= self::TRUNCATED_FROM_BYTES) {
            $start = substr($value, 0, self::ANCHOR_BYTES);
            $end = substr($value, -self::ANCHOR_BYTES);

            if ($this->isAnchor($start)) {
                $this->startAnchors[] = strtolower($start);
            }

            if ($this->isAnchor($end)) {
                $this->endAnchors[] = strtolower($end);
            }
        }
    }

    /**
     * The whole encoding (padded and not), then the core at each alignment: after 0, 1 or 2 prefix bytes, without the
     * leading characters that mix in prefix bits and the trailing one that mixes in the next byte.
     *
     * @return list<string>
     */
    private function base64Forms(string $value): array
    {
        $forms = [base64_encode($value), rtrim(base64_encode($value), '=')];

        foreach ([0 => 0, 1 => 2, 2 => 3] as $prefix => $drop) {
            $encoded = rtrim(base64_encode(str_repeat("\0", $prefix).$value), '=');
            $trailing = ($prefix + strlen($value)) % 3 === 0 ? 0 : 1;
            $core = substr($encoded, $drop, strlen($encoded) - $drop - $trailing);

            if (strlen($core) >= self::MIN_BASE64_CORE) {
                $forms[] = $core;
            }
        }

        return $forms;
    }

    private function isAnchor(string $anchor): bool
    {
        if (strspn($anchor, self::TOKEN_CHARS) !== strlen($anchor)) {
            return false;
        }

        foreach (self::SHAPE_PREFIXES as $prefix) {
            if (str_starts_with($prefix, strtolower($anchor))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every occurrence of $form, overlapping ones folded into one span as they are found.
     *
     * @param  list<array{int, int}>  $spans
     */
    private function collect(array &$spans, string $haystack, string $form): void
    {
        $length = strlen($form);
        $open = null;

        for ($at = strpos($haystack, $form); $at !== false; $at = strpos($haystack, $form, $at + 1)) {
            if ($open !== null && $at <= $open[1]) {
                $open[1] = $at + $length;

                continue;
            }

            if ($open !== null) {
                $spans[] = $open;
            }

            $open = [$at, $at + $length];
        }

        if ($open !== null) {
            $spans[] = $open;
        }
    }

    /**
     * @param  list<array{int, int}>  $spans
     */
    private function apply(string $text, array $spans, string $mask): string
    {
        if ($spans === []) {
            return $text;
        }

        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $output = '';
        $position = 0;
        $started = false;

        foreach ($spans as [$start, $end]) {
            if ($started && $start <= $position) {
                $position = max($position, $end);

                continue;
            }

            $output .= substr($text, $position, $start - $position).$mask;
            $position = $end;
            $started = true;
        }

        return $output.substr($text, $position);
    }

    /**
     * A word holding `&` or `%` whose decoded form holds a value is masked whole (`&#x68;unter22x`, `hunter%2522x`).
     */
    private function maskDecodedWords(string $text, string $mask): string
    {
        if (strpbrk($text, '&%') === false) {
            return $text;
        }

        $output = '';
        $position = 0;
        $length = strlen($text);

        while ($position < $length) {
            $blank = strspn($text, self::WHITE_SPACE, $position);
            $output .= substr($text, $position, $blank);
            $position += $blank;
            $wordLength = strcspn($text, self::WHITE_SPACE, $position);
            $word = substr($text, $position, $wordLength);
            $position += $wordLength;

            $output .= strpbrk($word, '&%') !== false && $this->decodedHoldsValue($word) ? $mask : $word;
        }

        return $output;
    }

    private function decodedHoldsValue(string $word): bool
    {
        $decoded = strtolower(self::decode($word));

        foreach ($this->values as $value) {
            if (str_contains($decoded, $value)) {
                return true;
            }
        }

        return false;
    }
}
