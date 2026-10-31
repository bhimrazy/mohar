<?php

/*
 * Guards for the demo-seed step of deploy/bin/smoke (#126): the script refuses a project that is not a smoke project
 * before it creates or removes anything, and the dev dependencies the seeders need live in their own Dockerfile
 * target, never in the prod image.
 */

use Symfony\Component\Process\Process;

function smokeRepoFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/'.$path);
}

test('the smoke script refuses a project name that is not a smoke project, before touching anything', function () {
    $script = dirname(__DIR__, 4).'/deploy/bin/smoke';

    foreach (['mohar', 'mohar-prod', 'mohar-smokey'] as $project) {
        $process = new Process(['bash', $script], null, ['SMOKE_PROJECT' => $project, 'PATH' => '/nonexistent:/usr/bin:/bin']);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain("SMOKE_PROJECT='{$project}' is not a smoke project");
    }

    $script = smokeRepoFile('deploy/bin/smoke');
    expect(strpos($script, 'is not a smoke project'))->toBeLessThan(strpos($script, 'trap cleanup EXIT'));
});

test('the demo seed runs from the smoke-seed target, with a project guard and an APP_ENV guard, never from the prod image', function () {
    $script = smokeRepoFile('deploy/bin/smoke');
    $dockerfile = smokeRepoFile('deploy/Dockerfile');

    expect($script)->toContain('--target smoke-seed')
        ->and($script)->toContain('test "$APP_ENV" != production && php artisan db:seed --force')
        ->and($script)->toContain('refusing to seed demo data with APP_ENV=production')
        ->and($script)->toContain('could not count users');

    $seed = substr($dockerfile, (int) strpos($dockerfile, 'AS smoke-seed'));
    $seed = substr($seed, 0, (int) strpos($seed, 'FROM scratch AS release'));
    $prod = substr($dockerfile, (int) strpos($dockerfile, 'FROM runtime AS prod'));

    expect($seed)->toContain('composer install --no-scripts')
        ->and($seed)->not->toContain('--no-dev')
        ->and($prod)->not->toContain('smoke-seed')
        ->and(substr_count($dockerfile, '--no-dev'))->toBe(2);
});
