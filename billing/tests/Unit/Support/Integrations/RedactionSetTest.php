<?php

/*
 * ADR 0023 §2 match forms and their bounds, through RedactionSet and the Redactor. The corpus comes from
 * Tests\Support\Redaction\MatchForms: every form × every placement, checked against its slow reference.
 */

use App\Support\Integrations\RedactionSet;
use App\Support\Integrations\Redactor;
use Tests\Support\Redaction\MatchForms;

const CORPUS_CREDENTIALS = [
    'ab1$', 'Kq9!mZ2', 'Pa55word', 'hunter22x', 'p&ss<w0rd>', 'abc/def+ghi=jk', 'Zx9-Lm2_Qr7+Ty4/', 'Hx7q-p9Lab3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY',
    'pässwörd123', 'it\'s "quoted"',
];

const CORPUS_ANSWERS = ['9812345678', '51824753556', 'AB12 3456', 'Ram Bahadur', '2026', 'KTM1'];

test('the corpus reaches every form in every placement, and the reference sees each echo', function () {
    $count = 0;

    foreach (CORPUS_CREDENTIALS as $value) {
        foreach (MatchForms::credential($value) as $form => $echo) {
            foreach (MatchForms::placed($echo) as $place => $text) {
                expect(MatchForms::credentialFound($text, $value))->not->toBe([], "{$value} {$form} {$place}");
                $count++;
            }
        }
    }

    foreach (CORPUS_ANSWERS as $answer) {
        foreach (MatchForms::answer($answer) as $form => $echo) {
            foreach (MatchForms::placed($echo) as $place => $text) {
                expect(MatchForms::answerFound($text, $answer))->toBeTrue("{$answer} {$form} {$place}");
                $count++;
            }
        }
    }

    expect($count)->toBeGreaterThan(2000);
});

test('no credential form leaves anything the reference can find', function () {
    $redactor = new Redactor;
    $leaks = [];

    foreach (CORPUS_CREDENTIALS as $value) {
        $set = RedactionSet::of([$value]);

        foreach (MatchForms::credential($value) as $form => $echo) {
            foreach (MatchForms::placed($echo) as $place => $text) {
                foreach (['maskValues' => $redactor->maskValues($text, $set), 'redact' => (string) json_encode($redactor->redact(['note' => $text], $set), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] as $surface => $masked) {
                    foreach (MatchForms::credentialFound($masked, $value) as $why) {
                        $leaks[] = "{$surface} {$value} {$form} {$place}: {$why}";
                    }
                }
            }
        }
    }

    expect($leaks)->toBe([]);
});

test('no answer regrouping leaves the answer readable', function () {
    $redactor = new Redactor;
    $leaks = [];

    foreach (CORPUS_ANSWERS as $answer) {
        $set = RedactionSet::of([], [$answer]);

        foreach (MatchForms::answer($answer) as $form => $echo) {
            foreach (MatchForms::placed($echo) as $place => $text) {
                if (MatchForms::answerFound($redactor->maskValues($text, $set), $answer)) {
                    $leaks[] = "{$answer} {$form} {$place}";
                }
            }
        }
    }

    expect($leaks)->toBe([]);
});

test('values are trimmed, and each long line of a multi-line secret is matched on its own', function () {
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQ\nAAAAAAAAAAEAAAAzAAAAC3NzaC1lZDI1NTE5AAAAIK\n-----END OPENSSH PRIVATE KEY-----";
    $set = RedactionSet::of(["  hunter22x \n", $key]);
    $crlf = str_replace("\n", "\r\n", $key);

    expect($set->mask('pw hunter22x ok'))->toBe('pw [redacted] ok')
        ->and($set->mask("dump:\r\n{$crlf}"))->not->toContain('b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQ')
        ->not->toContain('AAAAAAAAAAEAAAAzAAAAC3NzaC1lZDI1NTE5AAAAIK')
        ->and($set->mask('rewrapped: b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQ'))->toBe('rewrapped: [redacted]');
});

test('a value under 4 bytes is not matched, and one of 4 is', function () {
    expect(RedactionSet::of(['abc'])->mask('abc abcdef xabc'))->toBe('abc abcdef xabc')
        ->and(RedactionSet::of(['abc'])->isEmpty())->toBeTrue()
        ->and(RedactionSet::of(['ab1$'])->mask('key AB1$ end'))->toBe('key [redacted] end');
});

test('hex forms start at 8 bytes', function () {
    expect(RedactionSet::of(['Kq9!mZ2'])->mask('x '.bin2hex('Kq9!mZ2')))->toBe('x '.bin2hex('Kq9!mZ2'))
        ->and(RedactionSet::of(['Pa55word'])->mask('x '.strtoupper(bin2hex('Pa55word'))))->toBe('x [redacted]');
});

test('shifted base64 cores are built only when 8 characters or more remain', function () {
    $short = 'Qw3$e4';
    $long = 'Kq9!mZ2';

    // One and two prefix bytes shift the alignment (three would not).
    foreach (['x', 'u:'] as $prefix) {
        $shortEcho = base64_encode($prefix.$short.'@h');
        $longEcho = base64_encode($prefix.$long.'@h');

        expect(RedactionSet::of([$short])->mask("Basic {$shortEcho}"))->toBe("Basic {$shortEcho}")
            ->and(RedactionSet::of([$long])->mask("Basic {$longEcho}"))->not->toContain($longEcho)
            ->and(MatchForms::credentialFound(RedactionSet::of([$long])->mask("Basic {$longEcho}"), $long))->toBe([]);
    }

    // The whole encoding of a short value is still matched exactly.
    expect(RedactionSet::of([$short])->mask('Basic '.base64_encode($short)))->toBe('Basic [redacted]');
});

test('truncated echoes are masked from 16 bytes, by 8-byte token anchors only', function () {
    $key = 'Hx7q-p9Lab3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY';

    expect(RedactionSet::of([$key])->mask('invalid key Hx7q-p9Lab3d... (truncated)'))->toBe('invalid key [redacted]... (truncated)')
        ->and(RedactionSet::of([$key])->mask('key ending …vW1xY and …pQ5rS7tU9vW1xY'))->toBe('key ending …vW1xY and …[redacted]')
        // a 7-byte head is under the bound
        ->and(RedactionSet::of([$key])->mask('Hx7q-p9 only'))->toBe('Hx7q-p9 only')
        // 15 bytes: no anchors
        ->and(RedactionSet::of(['Hx7q-p9Lab3dE5f'])->mask('Hx7q-p9La...'))->toBe('Hx7q-p9La...')
        // an anchor holding `:` or equal to a shape prefix is not built
        ->and(RedactionSet::of(['user:pw-12345678901234'])->mask('user:pw-xyz'))->toBe('user:pw-xyz')
        ->and(RedactionSet::of(['sk_live_'.str_repeat('a', 8).'Zz9Yy8Xx7'])->mask('sk_live_other'))->toBe('sk_live_other');
});

test('short answers do not extend a run of their kind; long ones match anywhere', function () {
    $short = RedactionSet::of([], ['2026', 'KTM1']);
    $long = RedactionSet::of([], ['9812345678']);

    expect($short->mask('year 2026, ref 20261, KTM1 and KTM12, ABKTM1'))->toBe('year [redacted], ref 20261, [redacted] and KTM12, ABKTM1')
        ->and($long->mask('+9779812345678 and 00९८१२३४५६७८ and 129812345678555'))->toBe('+977[redacted] and 00[redacted] and 12[redacted]555')
        // four separators between two digits are past the bound
        ->and($long->mask('98 - - 12345678'))->toBe('98 - - 12345678');
});

test('answers are matched on a scrubbed copy, and a pattern that fails withholds the text', function () {
    $set = RedactionSet::of([], ['9812345678']);

    expect($set->mask("bad \xFF byte 981-234-5678"))->toBe('bad ? byte [redacted]')
        ->and($set->matches("only \xFF"))->toBeFalse();

    ini_set('pcre.backtrack_limit', '1');
    ini_set('pcre.jit', '0');

    try {
        expect($set->mask(str_repeat('9 ', 50)))->toBe(Redactor::MASK);
    } finally {
        ini_restore('pcre.backtrack_limit');
        ini_restore('pcre.jit');
    }
});

test('ordinary text keeps every word with a realistic set', function () {
    $short = 'Qw3$e4';
    $cores = [];

    foreach (['', "\0", "\0\0"] as $prefix) {
        $cores[] = substr(rtrim(base64_encode($prefix.$short), '='), strlen($prefix) + 1, 4);
    }

    $text = implode("\n", [
        'Your hosting account for example.com.np was created on 2026-10-23 at 10:15 Nepal time.',
        'नमस्ते, तपाईंको खाता सक्रिय छ। Invoice #10234 for NPR 1,130.00 (VAT 13%) is due.',
        '{"status":"error","code":4012,"message":"Domain not available","next":"https://api.example.test/v1?page=2"}',
        'Authorization failed: the account is locked until 11:00. Contact support@example.test.',
        'Words that hold short cores: '.implode(' ', array_map(fn (string $core): string => "pre{$core}post", $cores)),
        base64_encode('The quick brown fox jumps over the lazy dog 0123456789'),
        bin2hex('ordinary bytes!'),
    ]);
    $set = RedactionSet::of(['hunter22x', 'Pa55word', 'Kq9!mZ2', $short, 'abc', 'Zx9-Lm2_Qr7+Ty4/'], ['9812345678', 'KTM1', '2025']);

    expect($set->mask($text))->toBe($text)
        ->and((new Redactor)->maskValues($text, $set))->toBe((new Redactor)->maskValues($text, []));
});

test('masking stays linear with a 16-value set, a multi-line key among them', function () {
    $values = array_map(fn (int $i): string => 'secret-value-'.$i.'-'.str_repeat(chr(65 + $i), 6), range(1, 15));
    $values[] = "-----BEGIN PRIVATE KEY-----\n".implode("\n", str_split(base64_encode(str_repeat('k', 600)), 64))."\n-----END PRIVATE KEY-----";
    $set = RedactionSet::of($values, ['9812345678', 'Ram Bahadur']);
    $line = 'note=ordinary text with a value secret-value-3-DDDDDD and 981 234 5678 & more%20words ';
    $text = str_repeat($line, intdiv(1024 * 1024, strlen($line)));
    $before = memory_get_usage();

    $started = hrtime(true);
    $masked = (new Redactor)->maskValues($text, $set);
    $maskSeconds = (hrtime(true) - $started) / 1e9;
    $started = hrtime(true);
    $redacted = (new Redactor)->redact(['body' => $text], $set);
    $redactSeconds = (hrtime(true) - $started) / 1e9;

    expect($maskSeconds)->toBeLessThan(2.0)
        ->and($redactSeconds)->toBeLessThan(2.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(64 * 1024 * 1024)
        ->and($masked)->not->toContain('secret-value-3')->not->toContain('981 234 5678')
        ->and($redacted['body'])->not->toContain('secret-value-3');
});
