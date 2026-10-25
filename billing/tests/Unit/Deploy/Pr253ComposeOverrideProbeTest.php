<?php

/*
 * Review board probe for PR #253 (#25, head c89c1da7): DEPLOY_COMPOSE_OVERRIDE in deploy/bin/deploy. Runs a full
 * deploy, a rollback and status against the fake `docker` of DeployScriptProbesTest (read from that file) and checks
 * that every compose call carries the override when it is set (a path with a space included), and none when it is not.
 * Passes at c89c1da7.
 */

use Symfony\Component\Process\Process;

function p253FakeDocker(): string
{
    preg_match("/<<<'BASH'\n(.*?)\nBASH;/s", (string) file_get_contents(__DIR__.'/DeployScriptProbesTest.php'), $m);

    return $m[1];
}

/** @return list<string> the recorded docker compose calls */
function p253DeployCalls(?string $override): array
{
    $dir = sys_get_temp_dir().'/p253-deploy-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0700, true);
    file_put_contents("{$dir}/bin/docker", p253FakeDocker());
    chmod("{$dir}/bin/docker", 0755);
    file_put_contents("{$dir}/env", "APP_DOMAIN=billing.example.com\n");

    try {
        foreach ([['--image', 'img:1'], ['--image', 'img:2'], ['rollback'], ['status']] as $args) {
            $process = new Process(['bash', dirname(__DIR__, 4).'/deploy/bin/deploy', ...$args], null, [
                'PATH' => "{$dir}/bin:".getenv('PATH'),
                'FAKE_DIR' => $dir,
                'DEPLOY_ENV_FILE' => "{$dir}/env",
                'DEPLOY_STATE_DIR' => "{$dir}/state",
                'DEPLOY_PROJECT' => 'probe',
                ...($override === null ? [] : ['DEPLOY_COMPOSE_OVERRIDE' => $override]),
            ]);
            $process->setTimeout(60)->run();
            expect($process->getExitCode())->toBe(0, implode(' ', $args).': '.$process->getErrorOutput());
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents("{$dir}/calls")), fn (string $call): bool => str_starts_with($call, 'compose ')));
    } finally {
        (new Process(['rm', '-rf', $dir]))->run();
    }
}

test('every compose call of a deploy, a rollback and status carries DEPLOY_COMPOSE_OVERRIDE, and none without it', function () {
    $with = p253DeployCalls('/etc/mohar/compose secrets.yaml');
    $without = p253DeployCalls(null);

    expect($with)->not->toBeEmpty()
        ->and(array_filter($with, fn (string $call): bool => ! str_contains($call, '-f deploy/compose.prod.yaml -f /etc/mohar/compose secrets.yaml --env-file')))->toBe([])
        ->and($without)->toHaveCount(count($with))
        ->and(array_filter($without, fn (string $call): bool => substr_count($call, ' -f ') !== 1))->toBe([]);
});
