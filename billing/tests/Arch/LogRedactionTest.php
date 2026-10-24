<?php

/*
 * The log backstop (ADR 0023 §1.2, §1.3, step 4): every log channel taps RedactLogs, and no code in app/ writes a log
 * line past it.
 */

use App\Support\Logging\RedactLogs;

test('every channel in config/logging.php taps RedactLogs, stack and null excepted', function () {
    $channels = (require base_path('config/logging.php'))['channels'];
    $untapped = array_keys(array_filter(
        $channels,
        fn (array $channel, string $name): bool => ! in_array($name, ['stack', 'null', 'emergency'], true) && ! in_array(RedactLogs::class, $channel['tap'] ?? [], true),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($untapped)->toBe([]);
});

test('app/ never logs past the processor', function () {
    $forbidden = '/\b(?:Log::build|LogManager|error_log|syslog|openlog)\s*\(|::build\s*\(\s*\[\s*[\'"]driver|\bSTDERR\b|php:\/\/stderr/i';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($token) ? $token[1] : $token);
        }

        if (preg_match($forbidden, $code, $match) === 1) {
            $found[] = str_replace(app_path().'/', '', $file->getPathname()).': '.$match[0];
        }
    }

    expect($found)->toBe([]);
});

afterAll(fn () => releaseArchCache());
