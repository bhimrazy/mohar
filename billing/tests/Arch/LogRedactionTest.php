<?php

use App\Contracts\Billing\CancelsHostedPayment;
use App\Contracts\Billing\ConnectionTester;
use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Billing\HostedPaymentDriver;
use App\Contracts\Billing\LooksUpRefunds;
use App\Contracts\Billing\ResumesHostedPayment;

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

/**
 * The PHP files under app/, keyed by path relative to it, with comments blanked (their line breaks kept).
 *
 * @return array<string, string>
 */
function logRedactionArchSources(): array
{
    $root = realpath(__DIR__.'/../../app');
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? str_repeat("\n", substr_count($token[1], "\n"))
                : (is_array($token) ? $token[1] : $token);
        }

        $sources[substr($file->getPathname(), strlen($root) + 1)] = $code;
    }

    return $sources;
}

// A bare `->build(` is every builder in app/ (invoice lines, sample contexts), so only the log-shaped calls are banned:
// `Log::build`, `app('log')->build`, `->build(['driver' …])`. RedactingLogManager taps those channels as well. Monolog
// itself (a new logger or handler, a handler pushed onto a channel after it was tapped) is for the backstop only.
test('app/ never logs past the processor', function () {
    $forbidden = '/\b(?:Log::build|Log::listen|LogManager|error_log|syslog|openlog)\s*\(|(?:::|->)build\s*\(\s*\[\s*[\'"]driver|\bapp\(\s*[\'"]log[\'"]\s*\)\s*->\s*(?:build|listen)\b|\bMessageLogged\b|\bSTDERR\b|\bSTDOUT\b|php:\/\/(?:stderr|stdout)/i';
    $monolog = '/\bMonolog\\\\|->\s*(?:pushHandler|setHandlers|getLogger)\s*\(|\bnew\s+\\\\?(?:[A-Za-z]+\\\\)*(?:StreamHandler|RotatingFileHandler|SyslogHandler|ErrorLogHandler)\b/';
    $found = [];

    foreach (logRedactionArchSources() as $path => $code) {
        // The backstop's own classes use Monolog, and its event decorator masks MessageLogged.
        if (str_starts_with($path, 'Support/Logging/')) {
            $code = (string) preg_replace(['/\bMessageLogged\b/', $monolog], ' ', $code);
        }

        if (preg_match($forbidden, $code, $match) === 1 || preg_match($monolog, $code, $match) === 1) {
            $found[] = $path.': '.$match[0];
        }
    }

    expect($found)->toBe([]);
});

test('the Monolog ban matches the shapes it names', function (string $code) {
    $monolog = '/\bMonolog\\\\|->\s*(?:pushHandler|setHandlers|getLogger)\s*\(|\bnew\s+\\\\?(?:[A-Za-z]+\\\\)*(?:StreamHandler|RotatingFileHandler|SyslogHandler|ErrorLogHandler)\b/';

    expect(preg_match($monolog, $code))->toBe(1);
})->with([
    'use' => ['use Monolog\\Logger;'],
    'pushHandler' => ['Log::channel(\'x\')->getLogger()->pushHandler($h);'],
    'new handler' => ['new StreamHandler(\'php://memory\');'],
    'qualified' => ['new \\Monolog\\Logger(\'x\');'],
]);

/*
 * ADR 0023 §5, step 7: every Billing call to a gateway driver method that reaches the provider runs in
 * GatewayManager::during() (or RedactionScope::during()), so log lines and exceptions from it are masked by the
 * gateway's secrets. The methods are those of the gateway contracts, less the ones that read only the driver itself.
 */
test('every gateway driver call outside the drivers runs in the redaction scope', function () {
    $contracts = [
        GatewayDriver::class, HostedPaymentDriver::class,
        LooksUpRefunds::class, ResumesHostedPayment::class,
        CancelsHostedPayment::class, ConnectionTester::class,
    ];
    $local = ['key', 'name', 'capabilities', 'supports', 'currencies', 'settingsFields', 'instructions', 'limits', 'attemptTtl'];
    $methods = [];

    foreach ($contracts as $contract) {
        foreach ((new ReflectionClass($contract))->getMethods() as $method) {
            $methods[] = $method->getName();
        }
    }

    $calls = '/(?:\$driver|->(?:driver|hosted)\([^()]*\))\s*\??->\s*('.implode('|', array_diff(array_unique($methods), $local)).')\s*\(/';
    $checked = 0;
    $unscoped = [];

    foreach (logRedactionArchSources() as $path => $code) {
        if (! preg_match('#^Modules/(?:Billing|Payments[A-Za-z]*)/#', $path) || str_contains($path, '/Drivers/')) {
            continue;
        }

        // The smoke commands run all their checks in one RedactionScope::during() with the test key.
        $wholeFileScoped = preg_match('#Console/Commands/(Stripe|Razorpay)Smoke\.php$#', $path) === 1 && str_contains($code, 'RedactionScope::during(');
        preg_match_all($calls, $code, $found, PREG_OFFSET_CAPTURE);

        foreach ($found[0] as [$call, $offset]) {
            $lineStart = (int) strrpos(substr($code, 0, $offset), "\n");
            $line = substr($code, $lineStart, (int) strpos($code."\n", "\n", $offset) - $lineStart);
            $checked++;

            if (! $wholeFileScoped && ! str_contains($line, 'during(')) {
                $unscoped[] = $path.': '.trim($line);
            }
        }
    }

    expect($checked)->toBeGreaterThan(10)
        ->and($unscoped)->toBe([]);
});

afterAll(fn () => releaseArchCache());
