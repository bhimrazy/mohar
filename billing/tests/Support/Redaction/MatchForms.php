<?php

namespace Tests\Support\Redaction;

/**
 * The generated corpus of ADR 0023 §2 match forms, and a slow, plain reference that finds a value in text by decoding
 * every token every way. The Redactor must leave nothing the reference can find. Forms outside a written bound are
 * not generated (offset base64 under 7 bytes, hex under 8, truncated echoes under 16, answers under 4 letters and
 * digits or with more than 3 separators), so a reference hit is a leak, not a bound.
 */
final class MatchForms
{
    private const TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_+/=';

    /**
     * Echoes of a credential or request secret, keyed by form name.
     *
     * @return array<string, string>
     */
    public static function credential(string $value): array
    {
        $length = strlen($value);
        $forms = [
            'typed' => $value,
            'upper' => strtoupper($value),
            'lower' => strtolower($value),
            'title' => ucfirst(strtolower($value)),
            'urlencode' => urlencode($value),
            'rawurlencode' => rawurlencode($value),
            'urlencode twice' => urlencode(urlencode($value)),
            'json' => substr((string) json_encode($value), 1, -1),
            'html401' => htmlspecialchars($value, ENT_QUOTES | ENT_HTML401),
            'html5' => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5),
            'decimal entities' => implode('', array_map(fn (string $c): string => '&#'.mb_ord($c).';', mb_str_split($value))),
            'hex entities' => implode('', array_map(fn (string $c): string => '&#x'.dechex(mb_ord($c)).';', mb_str_split($value))),
            'padded decimal entities' => implode('', array_map(fn (string $c): string => ctype_alnum($c) ? $c : sprintf('&#%03d;', mb_ord($c)), mb_str_split($value))),
            'html then percent' => rawurlencode(htmlspecialchars($value, ENT_QUOTES)),
        ];

        foreach (['base64' => fn (string $s): string => base64_encode($s), 'base64url' => fn (string $s): string => strtr(base64_encode($s), '+/', '-_')] as $name => $encode) {
            $forms[$name] = $encode($value);
            $forms["{$name} unpadded"] = rtrim($encode($value), '=');

            if ($length >= 7) {
                foreach (['u:', 'us:', 'user:', 'ab'] as $prefix) {
                    foreach (['', ':x', '@host'] as $suffix) {
                        $forms["{$name} [{$prefix}]+value+[{$suffix}]"] = $encode($prefix.$value.$suffix);
                    }
                }
            }
        }

        if ($length >= 8) {
            $forms['hex'] = bin2hex($value);
            $forms['HEX'] = strtoupper(bin2hex($value));
        }

        if ($length >= 16) {
            foreach (['first 8' => substr($value, 0, 8), 'first 12' => substr($value, 0, 12)] as $name => $head) {
                if (self::isAnchor(substr($value, 0, 8))) {
                    $forms["truncated {$name}"] = $head.'...';
                    $forms["truncated {$name} glued"] = $head.'xyz';
                }
            }

            if (self::isAnchor(substr($value, -8))) {
                $forms['truncated last 8'] = '...'.substr($value, -8);
                $forms['truncated last 10'] = '…'.substr($value, -10);
            }
        }

        return $forms;
    }

    /**
     * Regroupings of a client answer, keyed by form name.
     *
     * @return array<string, string>
     */
    public static function answer(string $answer): array
    {
        $core = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $answer);
        $characters = mb_str_split($core);
        $devanagari = fn (string $s): string => (string) preg_replace_callback('/[0-9]/', fn (array $d): string => mb_chr(0x0966 + (int) $d[0]), $s);
        $grouped = fn (string $separator, int $size): string => implode($separator, array_map('implode', array_chunk($characters, $size)));

        $forms = [
            'typed' => $answer,
            'ungrouped' => $core,
            'upper' => mb_strtoupper($core),
            'lower' => mb_strtolower($core),
            'spaces of 3' => $grouped(' ', 3),
            'dashes of 4' => $grouped('-', 4),
            'dots of 2' => $grouped('.', 2),
            'commas of 3' => $grouped(',', 3),
            'slashes of 3' => $grouped('/', 3),
            'percent-encoded spaces' => $grouped('%20', 3),
            'plus between' => $grouped('+', 3),
            'three separators' => $grouped(' - ', 3),
            'devanagari digits' => $devanagari($core),
            'devanagari grouped' => $devanagari($grouped(' ', 3)),
            'half devanagari' => $devanagari(mb_substr($core, 0, intdiv(count($characters), 2))).mb_substr($core, intdiv(count($characters), 2)),
        ];

        if (count($characters) >= 10) {
            $forms['phone shape'] = '('.implode('', array_slice($characters, 0, 3)).') '.implode('', array_slice($characters, 3, 3)).'-'.implode('', array_slice($characters, 6));
        }

        if (count($characters) >= 8) {
            $forms['country code'] = '+977'.$core;
            $forms['zero padded'] = '00'.$core;
            $forms['inside a longer reference'] = 'REF'.$core.'X9';
        }

        return $forms;
    }

    /**
     * Where an echo sits in provider text.
     *
     * @return array<string, string>
     */
    public static function placed(string $echo): array
    {
        return [
            'alone' => $echo,
            'in a sentence' => "Login failed for {$echo}, please retry.",
            'at the end' => "rejected value: {$echo}",
            'in a JSON body' => (string) json_encode(['error' => ['detail' => "bad {$echo} here"]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'in a query' => "https://api.example.test/v1?user=1&v={$echo}&next=2",
            'in an XML element' => "<response><message>{$echo}</message></response>",
            'on a CRLF line' => "HTTP/1.1 400\r\nX-Note: {$echo}\r\n\r\nbody",
        ];
    }

    /**
     * Why the reference still finds $value in $text, if it does: one entry per decoding that reveals it.
     *
     * @return list<string>
     */
    public static function credentialFound(string $text, string $value): array
    {
        $found = [];
        $lowerValue = strtolower($value);
        $document = json_decode($text, true);

        if (is_array($document)) {
            array_walk_recursive($document, function (mixed $leaf) use (&$found, $value): void {
                array_push($found, ...(is_string($leaf) ? array_map(fn (string $why): string => 'in JSON: '.$why, self::credentialFound($leaf, $value)) : []));
            });
        }

        if (str_contains(strtolower($text), $lowerValue)) {
            $found[] = 'as typed';
        }

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $unescaped = json_decode('"'.$line.'"');

            if (is_string($unescaped) && str_contains(strtolower($unescaped), $lowerValue)) {
                $found[] = 'JSON-unescaped line';
            }
        }

        foreach ([...(preg_split('/\s+/', $text) ?: []), ...(preg_split('/\R/', $text) ?: [])] as $word) {
            $json = json_decode('"'.$word.'"');
            $hit = is_string($json) && str_contains(strtolower($json), $lowerValue);

            for ($decoded = $word, $pass = 0; $pass < 8 && ! $hit; $pass++) {
                $decoded = rawurldecode(html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $hit = str_contains(strtolower($decoded), $lowerValue) || str_contains(strtolower(strtr($decoded, '+', ' ')), $lowerValue);
            }

            if ($hit) {
                $found[] = 'decoded word '.$word;
            }
        }

        preg_match_all('/[0-9A-Fa-f]{8,}/', $text, $hexRuns);

        foreach ($hexRuns[0] as $run) {
            foreach ([0, 1] as $offset) {
                $even = substr($run, $offset, (strlen($run) - $offset) & ~1);

                if (str_contains((string) hex2bin($even), $value)) {
                    $found[] = 'hex '.$run;
                }
            }
        }

        preg_match_all('/[A-Za-z0-9+\/=_-]{6,}/', $text, $base64Runs);

        foreach ($base64Runs[0] as $run) {
            $standard = strtr(rtrim($run, '='), '-_', '+/');

            for ($offset = 0; $offset < 4; $offset++) {
                if (str_contains((string) base64_decode(substr($standard, $offset)), $value)) {
                    $found[] = 'base64 '.$run;
                }
            }
        }

        if (strlen($value) >= 16) {
            foreach ([substr($value, 0, 8), substr($value, -8)] as $anchor) {
                if (self::isAnchor($anchor) && str_contains(strtolower($text), strtolower($anchor))) {
                    $found[] = 'truncated '.$anchor;
                }
            }
        }

        return $found;
    }

    /**
     * Whether the answer's letters and digits are still in $text once separators and percent-encoding are removed and
     * Devanagari digits read as ASCII.
     */
    public static function answerFound(string $text, string $answer): bool
    {
        $fold = fn (string $s): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) preg_replace_callback('/[\x{0966}-\x{096F}]/u', fn (array $d): string => (string) (mb_ord($d[0]) - 0x0966), rawurldecode($s))));

        return str_contains($fold($text), $fold($answer));
    }

    private static function isAnchor(string $anchor): bool
    {
        if (strspn($anchor, self::TOKEN_CHARS) !== strlen($anchor)) {
            return false;
        }

        foreach (['sk_live_', 'sk_test_', 'rk_live_', 'rk_test_', 'whsec_', 'live_secret_key_', 'test_secret_key_'] as $prefix) {
            if (str_starts_with($prefix, strtolower($anchor))) {
                return false;
            }
        }

        return true;
    }
}
