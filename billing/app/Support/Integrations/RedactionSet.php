<?php

namespace App\Support\Integrations;

/**
 * The sensitive values of one call and the forms they are matched in (ADR 0023 §2): configured credentials and request
 * secrets ("values"), and clients' free-text answers ("answers", personal data such as a citizenship number). The forms
 * are built once, when the set is made, and reused for every string it masks.
 *
 * Each value is trimmed, then matched as a substring, on bytes, in these forms:
 * - as typed, urlencode, rawurlencode, JSON-escaped (`\/`, and `\u0026` for `&<>'"` as Go and JSON_HEX_* write it)
 *   and HTML-escaped, case-insensitively (ASCII case, on bytes);
 * - base64 and base64url, case-sensitively: the whole encoding with and without padding, and the core that stays the
 *   same whatever bytes sit before or after it (0, 1 or 2 prefix bytes, the dependent edge characters dropped), built
 *   only when that core is 8 characters or more, since a shorter one would match inside ordinary words;
 * - hex in either case, for values of 8 bytes or more;
 * - other entity, percent and `\uXXXX` spellings: a word (a run without white space) holding `&`, `%` or `\u` is
 *   decoded until stable (at most 6 passes) and masked whole when its decoded form holds a value;
 * - truncated echoes, for values of 16 bytes or more: from the value's first 8 bytes to the end of that run of token
 *   characters, and from the start of the run to the value's last 8 bytes. An anchor holding a non-token character,
 *   or one that is a known key-shape prefix (`sk_live_`) or the start of one, is not built.
 * A value holding a line break (a SecretText PEM or SSH key) also matches each of its lines of 16 bytes or more.
 * Values are not regrouped: a provider does not regroup a random token.
 *
 * Each answer matches its letters and digits in order, case-insensitively (Unicode), with 0 to 3 separators between
 * two of them: a run of white space, any other character that is not a letter or digit, or a percent-encoded byte
 * (`(981) 234-5678`, `518    247`, `518%20247`).
 * A digit also matches its Devanagari form. An answer of 8 letters and digits or more matches anywhere (`9812345678`
 * in `+9779812345678`); a shorter one must not extend a run of the same kind (`2026` alone, not inside `20261`).
 * Answers are matched on an mb_scrub()bed copy, which is what is returned; if a pattern fails the text is withheld.
 *
 * Bounds (ADR 0023 Consequences): values under 4 bytes and answers under 4 letters and digits are not matched,
 * truncated echoes under 8 bytes stay readable, the offset base64 cores exist only for values of about 7 bytes or
 * more, and a short answer inside a longer run of its kind stays readable.
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

    /** Answers from this many letters and digits match anywhere; shorter ones only outside a longer run of their kind. */
    private const ANSWER_ANYWHERE_FROM = 8;

    /** Between two characters of an answer: up to 3 separators (a white-space run counts as one), possessive, so nothing backtracks. */
    private const ANSWER_SEPARATORS = '(?:%[0-9A-Fa-f]{2}|\s++|[^\p{L}\p{N}\s]){0,3}+';

    private const DIGIT_RUN = '0-9\x{0966}-\x{096F}';

    private const WHITE_SPACE = " \t\r\n\f\v";

    /** Texts up to this length are first checked against the forms' leading 4 bytes, which is cheaper than a scan per form. */
    private const SHORT_TEXT_BYTES = 256;

    /** Results matches() remembers before it starts over (keys repeat in every object of a list); only texts up to SHORT_TEXT_BYTES are kept. */
    private const MATCH_CACHE_SIZE = 4096;

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

    /** @var list<string> one `/iu` pattern per answer */
    private array $answerPatterns = [];

    /** @var array<string, true> the first 4 bytes of every folded form and anchor */
    private array $foldedGrams = [];

    /** @var array<string, true> the first 4 bytes of every exact form */
    private array $exactGrams = [];

    /** @var array<string, bool> */
    private array $matchCache = [];

    /** @var list<string> lowercased forms and anchors made only of characters a number's digits can hold */
    private array $numericForms = [];

    /** @var list<string> patterns of answers made only of digits */
    private array $numericAnswerPatterns = [];

    /**
     * @param  list<string>  $values
     * @param  list<string>  $answers
     */
    private function __construct(array $values, array $answers)
    {
        foreach ($answers as $answer) {
            $pattern = self::answerPattern($answer);

            if ($pattern !== null) {
                $this->answerPatterns[] = $pattern;

                if (preg_match('/\p{L}/u', $answer) !== 1) {
                    $this->numericAnswerPatterns[] = $pattern;
                }
            }
        }

        $this->answerPatterns = array_values(array_unique($this->answerPatterns));

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

        foreach ([...$this->foldedForms, ...$this->startAnchors, ...$this->endAnchors] as $form) {
            $this->foldedGrams[substr($form, 0, self::MIN_MATCH_BYTES)] = true;
        }

        foreach ($this->exactForms as $form) {
            $this->exactGrams[substr($form, 0, self::MIN_MATCH_BYTES)] = true;
        }

        foreach ([...$this->foldedForms, ...array_map('strtolower', $this->exactForms), ...$this->startAnchors, ...$this->endAnchors] as $form) {
            if (self::isNumeric($form)) {
                $this->numericForms[] = $form;
            }
        }
    }

    /**
     * @param  list<string>  $values  credentials and request secrets
     * @param  list<string>  $answers  clients' free-text answers
     */
    public static function of(array $values, array $answers = []): self
    {
        return new self($values, $answers);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function isEmpty(): bool
    {
        return $this->values === [] && $this->answerPatterns === [];
    }

    /**
     * Every match of a value in $text replaced by $mask. Overlapping and adjacent matches become one mask.
     */
    public function mask(string $text, string $mask = Redactor::MASK): string
    {
        if ($this->isEmpty() || $text === '') {
            return $text;
        }

        if ($this->values === []) {
            return $this->maskAnswers($text, $mask);
        }

        $folded = strtolower($text);
        $spans = [];

        if (strlen($text) <= self::SHORT_TEXT_BYTES && ! $this->mayHoldForm($text, $folded)) {
            return $this->maskAnswers($this->maskDecodedWords($text, $mask), $mask);
        }

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
            // An occurrence inside the open span extends it; otherwise the backward walk stops at the previous span's
            // end. Each byte is walked once per anchor, and periodic text (`000…`) makes one span, not one per byte.
            $open = null;

            for ($at = strpos($folded, $anchor); $at !== false; $at = strpos($folded, $anchor, $at + 1)) {
                if ($open !== null && $at <= $open[1]) {
                    $open[1] = $at + self::ANCHOR_BYTES;

                    continue;
                }

                $floor = $open[1] ?? 0;

                if ($open !== null) {
                    $spans[] = $open;
                }

                $start = $at;

                while ($start > $floor && str_contains(self::TOKEN_CHARS, $text[$start - 1])) {
                    $start--;
                }

                $open = [$start, $at + self::ANCHOR_BYTES];
            }

            if ($open !== null) {
                $spans[] = $open;
            }
        }

        return $this->maskAnswers($this->maskDecodedWords($this->apply($text, $spans, $mask), $mask), $mask);
    }

    /**
     * Whether $text holds a value in any form (a key or a numeric leaf the caller withholds whole).
     */
    public function matches(string $text): bool
    {
        // Every form and answer is at least 4 bytes long.
        if (strlen($text) < self::MIN_MATCH_BYTES || $this->isEmpty()) {
            return false;
        }

        if (isset($this->matchCache[$text])) {
            return $this->matchCache[$text];
        }

        if (count($this->matchCache) >= self::MATCH_CACHE_SIZE) {
            $this->matchCache = [];
        }

        // A number's digits (an integer or float leaf, a list index) can hold only a numeric form or a digit answer.
        if (self::isNumeric($text)) {
            $matches = $this->numericMatches($text);
        } else {
            $masked = $this->mask($text, "\0");
            // With answers, an unmatched text still comes back mb_scrub()bed.
            $matches = $masked !== $text && ($this->answerPatterns === [] || $masked !== mb_scrub($text, 'UTF-8'));
        }

        if (strlen($text) <= self::SHORT_TEXT_BYTES) {
            $this->matchCache[$text] = $matches;
        }

        return $matches;
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

    /** `\uXXXX` escapes (JSON, Go, JavaScript) decoded; an escape that is not a character is kept. */
    private static function unescapeJson(string $text): string
    {
        if (! str_contains($text, '\\u')) {
            return $text;
        }

        return (string) preg_replace_callback('/\\\\u([0-9A-Fa-f]{4})/', function (array $escape): string {
            $code = (int) hexdec($escape[1]);

            // A lone surrogate half is not a character.
            return $code >= 0xD800 && $code <= 0xDFFF ? $escape[0] : mb_chr($code, 'UTF-8');
        }, $text);
    }

    private function maskAnswers(string $text, string $mask): string
    {
        if ($this->answerPatterns === []) {
            return $text;
        }

        return preg_replace($this->answerPatterns, $mask, mb_scrub($text, 'UTF-8')) ?? $mask;
    }

    /**
     * The answer's letters and digits in order with optional separators between them, or null when it has fewer than
     * 4. A digit matches its ASCII and Devanagari forms, so no copy of the text is folded and offsets stay put.
     */
    private static function answerPattern(string $answer): ?string
    {
        $characters = preg_split('//u', (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_scrub($answer, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($characters) < self::MIN_MATCH_BYTES) {
            return null;
        }

        $parts = array_map(function (string $character): string {
            $digit = self::digitValue($character);

            return $digit === null ? preg_quote($character, '/') : '['.$digit.mb_chr(0x0966 + $digit).']';
        }, $characters);
        $pattern = implode(self::ANSWER_SEPARATORS, $parts);

        if (count($characters) < self::ANSWER_ANYWHERE_FROM) {
            $edge = fn (string $character): string => self::digitValue($character) === null ? '\p{L}' : '['.self::DIGIT_RUN.']';
            $pattern = '(?<!'.$edge($characters[0]).')'.$pattern.'(?!'.$edge($characters[count($characters) - 1]).')';
        }

        return '/'.$pattern.'/iu';
    }

    /** 0-9 for an ASCII or Devanagari digit, otherwise null. */
    private static function digitValue(string $character): ?int
    {
        $code = mb_ord($character);

        return match (true) {
            $code >= 0x30 && $code <= 0x39 => $code - 0x30,
            $code >= 0x0966 && $code <= 0x096F => $code - 0x0966,
            default => null,
        };
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

        foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_HEX_TAG | JSON_HEX_AMP, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT] as $flags) {
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

    private static function isNumeric(string $text): bool
    {
        // No exponent letters: a key like E1234567 (a passport number) must reach the answer patterns.
        return strspn($text, '0123456789.-+') === strlen($text);
    }

    private function numericMatches(string $text): bool
    {
        foreach ($this->numericForms as $form) {
            if (str_contains($text, $form)) {
                return true;
            }
        }

        foreach ($this->numericAnswerPatterns as $pattern) {
            if (preg_match($pattern, $text) !== 0) {
                return true;
            }
        }

        return false;
    }

    /** Whether a short text holds the leading 4 bytes of any form or anchor; false means no form can match. */
    private function mayHoldForm(string $text, string $folded): bool
    {
        for ($at = 0, $last = strlen($text) - self::MIN_MATCH_BYTES; $at <= $last; $at++) {
            if (isset($this->foldedGrams[substr($folded, $at, self::MIN_MATCH_BYTES)]) || isset($this->exactGrams[substr($text, $at, self::MIN_MATCH_BYTES)])) {
                return true;
            }
        }

        return false;
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
     * A word holding `&`, `%` or `\u` whose decoded form holds a value is masked whole (`&#x68;unter22x`,
     * `hunter%2522x`, `\u0068unter22x`).
     */
    private function maskDecodedWords(string $text, string $mask): string
    {
        if (strpbrk($text, '&%') === false && ! str_contains($text, '\\u')) {
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

            $output .= (strpbrk($word, '&%') !== false || str_contains($word, '\\u')) && $this->decodedHoldsValue($word) ? $mask : $word;
        }

        return $output;
    }

    private function decodedHoldsValue(string $word): bool
    {
        $decoded = strtolower(self::decode(self::unescapeJson($word)));
        // urlencode() writes a space as `+`, which rawurldecode() keeps.
        $spaced = strtr($decoded, '+', ' ');

        foreach ($this->values as $value) {
            if (str_contains($decoded, $value) || str_contains($spaced, $value)) {
                return true;
            }
        }

        return false;
    }
}
