<?php

/*
 * Drift guards for the load-test harness (PR #108). loadtest/ carries three hand-made copies of deploy/ that have no
 * other link to their source: the FrankenPHP Caddyfile (plus `num_threads`, which Caddy cannot set to "auto"), the
 * Caddy upstream that deploy/bin/deploy writes for the live colour, and the list of blue services the production
 * compose file defines. Each guard compares the copy with the original, so a change to deploy/ that is not carried
 * over fails here instead of in a 20-minute runner run. The last test checks that every devops page the load-test
 * docs point at exists.
 */

const LOADTEST_ROOT = __DIR__.'/../../../..';

function loadtestFile(string $path): string
{
    $content = file_get_contents(LOADTEST_ROOT.'/'.$path);
    expect($content)->not->toBeFalse("{$path} should exist");

    return (string) $content;
}

/** @return list<string> */
function loadtestWords(string $shellArray): array
{
    return preg_split('/\s+/', trim($shellArray), -1, PREG_SPLIT_NO_EMPTY);
}

/** Service names of deploy/compose.prod.yaml whose block has `profiles: [blue]`. @return list<string> */
function loadtestBlueServices(): array
{
    preg_match_all('/^    ([a-z0-9-]+):\n((?:        .*\n|\n)*)/m', loadtestFile('deploy/compose.prod.yaml'), $blocks, PREG_SET_ORDER);
    $blue = [];
    foreach ($blocks as [, $name, $body]) {
        if (preg_match('/^\s+profiles: \[blue\]$/m', $body)) {
            $blue[] = $name;
        }
    }
    sort($blue);
    expect($blue)->not->toBeEmpty();

    return $blue;
}

test('the load-test Caddyfile is the production Caddyfile plus num_threads', function () {
    $production = loadtestFile('deploy/frankenphp/Caddyfile');
    $copy = loadtestFile('loadtest/frankenphp/Caddyfile');

    // The copy starts with its own comment, then repeats the production file from its first line on.
    $firstLine = strtok($production, "\n");
    $start = strpos($copy, $firstLine);
    expect($start)->not->toBeFalse('the copy should repeat the production Caddyfile from its first line');

    $expected = preg_replace('/^\tfrankenphp$/m', "\tfrankenphp {\n\t\tnum_threads {\$PHP_THREADS}\n\t}", $production, -1, $replaced);
    expect($replaced)->toBe(1, 'the production Caddyfile should have one bare `frankenphp` directive');
    expect(substr($copy, $start))->toBe($expected);
});

test('the upstream file bin/run writes for blue is what deploy/bin/deploy writes', function () {
    expect(preg_match("/^cat > \"\\\$secrets\\/state\\/live\\/upstream\\.caddy\" <<'CADDY'\n(.*?)^CADDY$/ms", loadtestFile('loadtest/bin/run'), $copy))->toBe(1);
    expect(preg_match("/^write_upstream\\(\\) \\{\n.*?<<CADDY\n(.*?)^CADDY$/ms", loadtestFile('deploy/bin/deploy'), $original))->toBe(1);

    $strip = fn (string $heredoc): string => preg_replace('/^\s*#.*\n/m', '', $heredoc);
    // The deploy heredoc is unquoted: `$1` is the colour and `\$` a literal dollar sign.
    $expected = str_replace(['$1', '\$'], ['blue', '$'], $strip($original[1]));

    expect($strip($copy[1]))->toBe($expected);
});

test('bin/run and the overlay name exactly the blue services of the production stack', function () {
    $blue = loadtestBlueServices();
    $run = loadtestFile('loadtest/bin/run');

    expect(preg_match('/^compose=\((.*)\)$/m', $run, $compose))->toBe(1)
        ->and(loadtestWords($compose[1]))->toContain('--profile', 'blue');

    expect(preg_match('/^stack=\((.*)\)$/m', $run, $stack))->toBe(1);
    $stack = loadtestWords($stack[1]);
    sort($stack);
    $expected = [...$blue, 'caddy'];
    sort($expected);
    expect($stack)->toBe($expected);

    expect(preg_match('/^pools=\((.*)\)$/m', $run, $pools))->toBe(1);
    $pools = loadtestWords($pools[1]);
    sort($pools);
    expect($pools)->toBe(array_values(array_filter($blue, fn (string $s) => str_starts_with($s, 'worker-') && $s !== 'worker-automation-blue')));

    preg_match_all('/^    ([a-z0-9-]+):$/m', loadtestFile('loadtest/compose.loadtest.yaml'), $overlay);
    $overlayBlue = array_values(array_filter($overlay[1], fn (string $s) => str_ends_with($s, '-blue')));
    sort($overlayBlue);
    expect($overlayBlue)->toBe($blue)
        ->and(array_filter($overlay[1], fn (string $s) => str_ends_with($s, '-green')))->toBeEmpty();

    preg_match_all('/^    ([a-z0-9-]+):$/m', loadtestFile('deploy/compose.prod.yaml'), $production);
    expect(array_values(array_diff($overlay[1], $production[1])))->toBe(['k6']);
});

test('bin/run removes the slice unit it writes and does not set the same limits twice', function () {
    $run = loadtestFile('loadtest/bin/run');

    expect($run)->toContain('rm -f "/etc/systemd/system/${slice}"')
        ->and($run)->not->toContain('set-property');
});

test('the storefront scenario names its domains per scenario run, so levels do not collide', function () {
    expect(loadtestFile('loadtest/k6/scenarios/storefront.js'))->toContain('lt-${exec.scenario.startTime}-${exec.vu.idInTest}');
});

test('every docs/devops page the load-test docs point at exists', function () {
    $pages = [];
    foreach (['loadtest/README.md', 'billing/docs/devops/ci.md', '.github/workflows/load-test.yml'] as $doc) {
        preg_match_all('#(?:billing/)?docs/devops/([a-z0-9-]+\.md)#', loadtestFile($doc), $m);
        $pages[$doc] = array_unique($m[1]);
    }

    expect(array_merge(...array_values($pages)))->toContain('sizing.md');
    foreach ($pages as $doc => $names) {
        foreach ($names as $name) {
            expect(file_exists(LOADTEST_ROOT."/billing/docs/devops/{$name}"))->toBeTrue("{$doc} points at billing/docs/devops/{$name}, which does not exist");
        }
    }
});
