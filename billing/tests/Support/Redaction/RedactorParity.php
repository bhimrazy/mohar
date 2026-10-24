<?php

namespace Tests\Support\Redaction;

/**
 * The parity guard of #252 (RedactorParityTest): a deterministic corpus, and the rule for what develop masked by
 * over-redaction that the Redactor now leaves readable on purpose. The corpus is every string literal in the Redactor
 * test files (`tests/Unit/Support/Integrations/*Redactor*Test.php`, the parity test itself excluded) plus generated
 * variants, so a probe folded into a themed file stays in it as long as the file name matches.
 */
final class RedactorParity
{
    private const TEST_DIRECTORY = __DIR__.'/../../Unit/Support/Integrations';

    /**
     * String literals from the Redactor test files that look like input (contain `=`, `:` or `<`).
     *
     * @return list<string>
     */
    public static function literals(): array
    {
        $literals = [];

        foreach (glob(self::TEST_DIRECTORY.'/*Redactor*Test.php') ?: [] as $file) {
            if (str_ends_with($file, 'RedactorParityTest.php')) {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $body = substr($token[1], 1, -1);
                    $literal = $token[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);

                    if (strpbrk($literal, '=:<') !== false) {
                        $literals[] = $literal;
                    }
                }
            }
        }

        return $literals;
    }

    /**
     * Generated variants.
     *
     * @return list<string>
     */
    public static function variants(): array
    {
        $names = ['password', 'api_key', 'token', 'client_secret', 'Authorization', 'access_token', 'Session', 'hash', 'domain'];
        $values = ['PARSECRET1', 'QWxhZGRpbjpvcGVuc2VzYW1l==', 'abcPAR=def', 'hunter2:PARNEXT', 'cs_live_a1B2c3D4', 'pi_a1B2c3D4.', 'Bearer PARBEARER'];
        $separators = ['=', ':', ': ', ' = ', ' : ', ":\t", '=>', ":\n", ":\r\n", "=\r\n", ":\r", ": \n  "];
        $wraps = [['', ''], ['msg ', "\nHost: a.test"], ['a:b:', '&next=1'], ['url?x=1&', ';'], ['(', ') retry'], ['', "\r\nContent-Type: text/plain"]];
        $variants = [];

        foreach ($names as $name) {
            foreach ($values as $value) {
                foreach ($separators as $separator) {
                    foreach ($wraps as [$before, $after]) {
                        $variants[] = $before.$name.$separator.$value.$after;
                    }
                }

                $variants[] = json_encode([$name => $value, 'next' => 'ok']);
                $variants[] = json_encode(['message' => $name.'='.$value]);
                $variants[] = 'q='.urlencode($name.'='.$value);
                $variants[] = urlencode($name).'%3D'.urlencode($value).'%26x%3D1';
                $variants[] = htmlspecialchars('<param name="'.$name.'">'.$value.'</param>');
                $variants[] = '"'.$name.'": "'.addslashes($value).'"';
                $variants[] = "'".$name."' => '".$value."'";
            }
        }

        $elements = [
            fn (string $n, string $c): string => "<{$n}>{$c}</{$n}>",
            fn (string $n, string $c): string => "<ns:{$n}>{$c}</ns:{$n}>",
            fn (string $n, string $c): string => "<param name=\"{$n}\">{$c}</param>",
            fn (string $n, string $c): string => "<param value=\"x\" name='{$n}'>{$c}</param>",
            fn (string $n, string $c): string => "<PARAM NAME = \"{$n}\">{$c}</PARAM>",
            fn (string $n, string $c): string => "<item key=\"{$n}\" type=\"string\">{$c}</item>",
            fn (string $n, string $c): string => "<item type=\"string\" key=\"{$n}\">{$c}</item>",
            fn (string $n, string $c): string => "<input name=\"{$n}\" value=\"{$c}\">",
            fn (string $n, string $c): string => "<link key=\"{$n}\">{$c}",
        ];
        $contents = [
            fn (string $v, string $eol): string => $v,
            fn (string $v, string $eol): string => " {$v} ",
            fn (string $v, string $eol): string => "<![CDATA[{$v}]]>",
            fn (string $v, string $eol): string => "{$eol}  <![CDATA[{$v}]]>{$eol}",
            fn (string $v, string $eol): string => "<!-- note -->{$v}",
            fn (string $v, string $eol): string => "<![CDATA[a]]>{$v}",
            fn (string $v, string $eol): string => "<value>{$v}</value>",
            fn (string $v, string $eol): string => "{$eol}  <value><string>{$v}</string></value>{$eol}",
        ];

        foreach (['password', 'api_key', 'Token', 'domain'] as $name) {
            foreach ($elements as $element) {
                foreach ($contents as $content) {
                    foreach (["\n", "\r\n"] as $eol) {
                        $xml = $element($name, $content('PARXML1', $eol));
                        $variants[] = $xml;
                        $variants[] = "<methodCall>{$eol}{$xml}{$eol}<param name=\"domain\">a.com.np</param></methodCall>";
                    }
                }
            }
        }

        foreach (['license', 'auth'] as $tag) {
            foreach (['"', "'"] as $q) {
                $variants[] = "<{$tag} key={$q}PARKEYATTR1{$q}/>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR2{$q} />";
                $variants[] = "<{$tag} key={$q}PARKEYATTR3{$q}></{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR4{$q}>\n  </{$tag}>";
                $variants[] = "<{$tag} type={$q}trial{$q} key={$q}PARKEYATTR5{$q} expires={$q}2027-01-01{$q}/>";
                $variants[] = "<{$tag} user={$q}u{$q} key={$q}PARKEYATTR6{$q}/>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR7{$q} name={$q}main{$q}/>";
                $variants[] = "<response>\n<{$tag} id={$q}7{$q} key={$q}PARKEYATTR8{$q}/>\n</response>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR11{$q}><child/></{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR12{$q}>\n  <child>x</child>\n</{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR13{$q}><!-- note --></{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR14{$q}> \t\r\n </{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR15{$q}><![CDATA[]]></{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR16{$q}><![CDATA[ \n ]]></{$tag}>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR17{$q} value={$q}{$q}/>";
                $variants[] = "<{$tag} key={$q}PARKEYATTR18{$q} value={$q} {$q}/>";
                $variants[] = "<{$tag} value={$q}\t{$q} name={$q}PARKEYATTR19{$q}></{$tag}>";
                $variants[] = "<{$tag} name={$q}PARKEYATTR20{$q}><child/></{$tag}>";
            }

            foreach (['', '/'] as $close) {
                $variants[] = "<{$tag} key=PARKEYATTR21 value=\"\"{$close}>";
            }

            $variants[] = "<{$tag} key=PARKEYATTR9/>";
            $variants[] = "<{$tag} key=PARKEYATTR10 />";
        }

        foreach (['key=PARKEY1', 'api-key=PARKEY2', 'x&key=PARKEY3&y=1', "password:\nhunter2:", "password:\nHunter2:", "secret:\nPARCOLON1:\r\nHost: a.test"] as $literal) {
            $variants[] = $literal;
        }

        return $variants;
    }

    /** @return list<string> */
    public static function corpus(): array
    {
        return array_values(array_unique([...self::literals(), ...self::variants()]));
    }

    /**
     * Whether develop masked $token by over-redaction this PR undoes on purpose (changelog, RedactorShapesTest), so it is
     * not a secret: every occurrence is a name (an element name, the `CDATA` of a CDATA section, the quoted value of a `key`/`name` attribute on an
     * element that carries its value elsewhere (see labelCount), or a capitalised header label at a line start with a value after `: `), or it is a documented reference value
     * (ResellerClub `auth-userid=…`, a Stripe-style id after a `Session:` or `hash:` label).
     */
    public static function readableOnPurpose(string $input, string $token): bool
    {
        $quoted = preg_quote($token, '/');
        $occurrences = preg_match_all('/(?<![A-Za-z0-9_])'.$quoted.'(?![A-Za-z0-9_])/', $input);
        $names = preg_match_all('/<\/?(?:[\w.-]+:)?\K'.$quoted.'(?![\w.:-])/', $input)
            + preg_match_all('/<!\[\K'.$quoted.'(?=\[)/', $input)
            + self::labelCount($input, $quoted)
            + preg_match_all('/(?:^|[\r\n])(?=[A-Z])[\w-]*?(?<![A-Za-z0-9_])\K'.$quoted.'(?=[\w-]*[ \t]*:[ \t]+[^ \t\r\n])(?![A-Za-z0-9_])/', $input);

        if ($names >= $occurrences) {
            return true;
        }

        return preg_match('/\bauth-userid[ \t]*=[ \t]*'.$quoted.'(?![A-Za-z0-9_])/i', $input) === 1
            || (preg_match('/^(?:cs|pi|ch|evt|cus|in|sub|re|py|seti)_(?:live_|test_)?[A-Za-z0-9]{4,}$/', $token) === 1
                && preg_match('/\b(?:session|hash)[ \t]*:\s*'.$quoted.'(?![A-Za-z0-9_])/i', $input) === 1);
    }

    /**
     * Quoted `key`/`name` attribute values equal to the token that label the element: the element carries its value
     * elsewhere, in a non-blank `value` attribute or in text right after the start tag (after blanks, a character other
     * than `<`, or a CDATA section with a non-blank character). A label whose element is self-closing, empty, or holds only
     * child elements, comments, blanks or blank CDATA is not counted, so parity checks it.
     */
    public static function labelCount(string $input, string $quoted): int
    {
        preg_match_all('/<[\w.:-]+((?:[ \t\r\n]+[\w.:-]+[ \t]*=[ \t]*(?:"[^"<>]*"|\'[^\'<>]*\'))*?)[ \t\r\n]+(?:key|name)[ \t]*=[ \t]*(["\'])'.$quoted.'\2([^<>]*)>/i', $input, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $count = 0;

        foreach ($tags as $tag) {
            $end = $tag[0][1] + strlen($tag[0][0]);
            $after = substr($input, $end + strspn($input, " \t\r\n", $end));
            $hasValue = preg_match('/(?<![\w.:-])value[ \t]*=[ \t]*(?:"[ \t\r\n]*[^"\s]|\'[ \t\r\n]*[^\'\s]|[^\s"\'>])/i', $tag[1][0].' '.$tag[3][0]) === 1;
            $hasText = ! str_ends_with(rtrim($tag[3][0]), '/')
                && preg_match('/^(?:[^<\s]|<!\[CDATA\[\s*(?!\]\]>)\S)/', $after) === 1;
            $count += (int) ($hasValue || $hasText);
        }

        return $count;
    }

    /**
     * Secret tokens in $input that $output no longer contains.
     *
     * @return list<string>
     */
    public static function maskedTokens(string $input, string $output): array
    {
        preg_match_all('/[A-Za-z0-9_]{4,}/', $input, $tokens);

        return array_values(array_filter(
            array_unique($tokens[0]),
            fn (string $token): bool => $token !== 'redacted' && ! str_contains($output, $token)
                && ! self::readableOnPurpose($input, $token),
        ));
    }
}
