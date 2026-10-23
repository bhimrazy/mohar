<?php

/*
 * Review board probes for PR #167 (D4): the cPanel cron lines are a copy of App\Support\Queues::workerCommands() and
 * the release tarball is the Dockerfile's `release` target. Both are copies that drift silently: the cron lines went
 * stale against develop (no `imports` queue, a made-up 90 s timeout) before the first merge.
 */

use App\Support\Queues;

/** The five cron entries of deploy/cpanel/crontab.example, without the schedule and the log redirect. */
function d4CronCommands(): array
{
    $lines = array_filter(
        file(dirname(__DIR__, 4).'/deploy/cpanel/crontab.example', FILE_IGNORE_NEW_LINES),
        fn (string $line): bool => str_starts_with($line, '* * * * * '),
    );

    return array_values(array_map(function (string $line): string {
        $line = preg_replace('/^\* \* \* \* \* cd "\$HOME\/billing" && /', '', $line);
        $line = preg_replace('/ >> storage\/logs\/cron\.log 2>&1$/', '', $line);

        return $line;
    }, $lines));
}

/** A cron queue:work entry reduced to the worker command of Queues::workerCommands(). */
function d4AsWorkerCommand(string $line): string
{
    $line = preg_replace('/^flock -n \S+ /', '', $line);
    $line = str_replace('/opt/cpanel/ea-php84/root/usr/bin/php', 'php', $line);

    // The only flags cron mode adds: stop when the queue is empty and before the next minute's worker starts.
    return str_replace(' --stop-when-empty --max-time=55', '', $line);
}

test('the crontab.example worker lines are exactly Queues::workerCommands() plus the cron-only flags', function () {
    $commands = d4CronCommands();

    expect($commands)->toHaveCount(5)
        ->and($commands[0])->toBe('/opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run');

    $workers = array_map('d4AsWorkerCommand', array_slice($commands, 1));

    expect($workers)->toBe(Queues::workerCommands());

    foreach (array_slice($commands, 1) as $line) {
        expect($line)->toContain('--stop-when-empty --max-time=55');
    }
});

test('every cron worker pool has its own flock file', function () {
    $locks = array_map(
        fn (string $line): string => preg_match('/^flock -n (\S+) /', $line, $m) === 1 ? $m[1] : $line,
        array_slice(d4CronCommands(), 1),
    );

    expect(array_unique($locks))->toHaveCount(4);
});

function d4Dockerfile(): string
{
    return file_get_contents(dirname(__DIR__, 4).'/deploy/Dockerfile');
}

/** The instructions of one build stage, from its FROM line to the next FROM. */
function d4Stage(string $name): string
{
    preg_match('/^FROM (\S+) AS '.preg_quote($name, '/').'\n(.*?)(?=^FROM |\z)/ms', d4Dockerfile(), $m) || throw new RuntimeException("no stage {$name}");

    return $m[1]."\n".$m[2];
}

test('the release target is built from the same stages as the prod image and contains only the app tree and the built assets', function () {
    $release = d4Stage('release');
    $prod = d4Stage('prod');

    expect($release)->toStartWith('scratch');

    preg_match_all('/^COPY --from=(\S+) (\S+) (\S+)$/m', $release, $copies, PREG_SET_ORDER);
    expect(array_map(fn (array $c): array => [$c[1], $c[2]], $copies))
        ->toBe([['release-tree', '/app'], ['assets', '/app/public/build']]);

    // release-tree is the vendor stage minus the front-end sources and dev configs, so it cannot drift from prod's code.
    $tree = d4Stage('release-tree');
    expect($tree)->toStartWith('vendor');

    foreach (['resources/js', 'package.json', 'package-lock.json', 'vite.config.ts', 'phpunit.xml', 'phpstan.neon', 'pint.json', 'tsconfig.json'] as $dev) {
        expect($tree)->toContain($dev);
    }

    // What the app reads at runtime stays: the Composer manifest, the views and the stylesheets.
    foreach (['composer.json', 'resources/views', 'resources/css', 'artisan'] as $kept) {
        expect($tree)->not->toContain($kept);
    }

    // The same two sources as prod's /app, so the tarball and the image cannot hold different code.
    expect($prod)->toContain('COPY --from=vendor /app /app')
        ->and($prod)->toContain('COPY --from=assets /app/public/build /app/public/build');

    // The vendor stage builds on the shared PHP base and installs without dev packages.
    expect(d4Stage('vendor'))->toStartWith('php')
        ->and(d4Stage('vendor'))->toContain('composer install --no-dev')
        ->and(d4Stage('vendor'))->toContain('composer dump-autoload --no-dev')
        ->and(d4Stage('dev'))->toStartWith('php');

    // The assets stage contributes only public/build: node_modules never leaves it.
    expect($release)->not->toContain('node_modules')
        ->and($release)->not->toContain('--from=dev');
});

test('the release tarball excludes .env, tests, docs, node_modules and dev vendor', function () {
    $ignore = file(dirname(__DIR__, 4).'/deploy/Dockerfile.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $ignore = array_values(array_filter($ignore, fn (string $line): bool => ! str_starts_with($line, '#')));

    // Everything is excluded unless it is re-included, and the re-included set is billing/ and the runtime files.
    expect($ignore[0])->toBe('*')
        ->and($ignore)->toContain('!billing');

    foreach (['billing/.env', 'billing/.env.*', 'billing/tests', 'billing/docs', 'billing/node_modules', 'billing/vendor', 'billing/auth.json'] as $path) {
        expect($ignore)->toContain($path);
    }

    // .env.example stays for the installer; real environments and local state never do.
    expect($ignore)->toContain('!billing/.env.example')
        ->and($ignore)->toContain('billing/storage/**');

    // The vendor stage's backstop fails the build on a leaked .env or storage file.
    expect(d4Stage('vendor'))->toContain("-name '.env'")
        ->and(d4Stage('vendor'))->toContain('find storage -type f ! -name .gitignore');
});

test('the draft release job is the only write-capable job besides the GHCR push, and runs only from the default branch for the built tag', function () {
    $workflow = file_get_contents(dirname(__DIR__, 4).'/.github/workflows/build-image.yml');
    preg_match('/^    release:\n(.*)\z/ms', $workflow, $m) || throw new RuntimeException('no release job');
    $job = $m[1];

    expect($workflow)->toContain("permissions:\n    contents: read\n")
        ->and($job)->toContain('contents: write')
        ->and($job)->toContain("github.ref == format('refs/heads/{0}', github.event.repository.default_branch)")
        ->and($job)->toContain('inputs.release_tag == inputs.ref')
        ->and($job)->toContain('--verify-tag --draft')
        ->and(preg_match_all('/^\s+contents: write$/m', $workflow))->toBe(1);
});
