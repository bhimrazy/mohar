<?php

/*
 * The log backstop (ADR 0023 §1.2, §1.3, step 4): every log channel taps RedactLogs, and no code in app/ writes a log
 * line past it.
 */

// Arch tests run without the application, so the config is read as text: each channel is a block indented by eight
// spaces under 'channels' (the layout Pint keeps).
test('every channel in config/logging.php taps RedactLogs, stack and null excepted', function () {
    $config = (string) file_get_contents(__DIR__.'/../../config/logging.php');
    $channels = substr($config, (int) strpos($config, "'channels' => ["));
    preg_match_all("/^ {8}'([a-z_]+)' => \\[\\n(.*?)^ {8}\\],/ms", $channels, $blocks, PREG_SET_ORDER);
    $untapped = [];

    foreach ($blocks as [, $name, $body]) {
        if (! in_array($name, ['stack', 'null', 'emergency'], true) && ! str_contains($body, "'tap' => [RedactLogs::class]")) {
            $untapped[] = $name;
        }
    }

    expect(count($blocks))->toBeGreaterThan(8)
        ->and($untapped)->toBe([]);
});

test('app/ never logs past the processor', function () {
    $forbidden = '/\b(?:Log::build|LogManager|error_log|syslog|openlog)\s*\(|::build\s*\(\s*\[\s*[\'"]driver|\bSTDERR\b|php:\/\/stderr/i';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($token) ? $token[1] : $token);
        }

        if (preg_match($forbidden, $code, $match) === 1) {
            $found[] = basename($file->getPathname()).': '.$match[0];
        }
    }

    expect($found)->toBe([]);
});

afterAll(fn () => releaseArchCache());
