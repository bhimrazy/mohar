<?php

/*
 * The one-shot migrate service in deploy/compose.prod.yaml (PR #306). `migrate --isolated` locks in cache_locks
 * (CACHE_STORE=database), so the cache-table migration runs on its own first, named by its path.
 */

use Symfony\Component\Yaml\Yaml;

test('the migrate service creates the cache tables first, then migrates one migrator at a time', function () {
    $command = implode(' ', Yaml::parseFile(dirname(__DIR__, 4).'/deploy/compose.prod.yaml')['services']['migrate']['command']);

    expect(preg_match('/migrate --force --no-interaction --path=(\S+) && php artisan migrate --isolated=1 /', $command, $match))->toBe(1)
        ->and(dirname(__DIR__, 3).'/'.$match[1])->toBeFile()
        ->and((string) file_get_contents(dirname(__DIR__, 3).'/'.$match[1]))->toContain("Schema::create('cache_locks'");
});
