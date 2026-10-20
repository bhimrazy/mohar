<?php

/*
 * The guard of one `simulate:e2e` run (ADR 0016 §5 guarantee 4). Started detached, in a session of its own, so a
 * Ctrl-C to the runner's process group does not reach it. While the state file exists it watches the runner; when the
 * runner is gone without having cleaned up (Ctrl-C, SIGTERM, a kill), it stops the scenario process and drops the
 * throwaway database. Needs no pcntl. Arguments: runner pid, state file. Environment: E2E_DB_CONNECTION, E2E_DB_NAME,
 * and for PostgreSQL E2E_PG_DSN, E2E_PG_USER, E2E_PG_PASSWORD.
 */

$runner = (int) ($argv[1] ?? 0);
$state = (string) ($argv[2] ?? '');

while ($state !== '') {
    // is_file() results are cached per path: clear the cache on every pass, or a removed state file looks present.
    clearstatcache();

    if (! is_file($state)) {
        exit(0);
    }

    if ($runner > 0 && posix_kill($runner, 0)) {
        usleep(100_000);

        continue;
    }

    $info = json_decode((string) @file_get_contents($state), true);
    $child = is_array($info) ? (int) ($info['child'] ?? 0) : 0;

    if ($child > 0 && posix_kill($child, 0)) {
        posix_kill($child, 15);
        usleep(500_000);
    }

    $name = (string) getenv('E2E_DB_NAME');

    if (getenv('E2E_DB_CONNECTION') === 'sqlite') {
        if (preg_match('/^billing_e2e_[0-9a-f]{8}\.sqlite$/', basename($name)) === 1) {
            @unlink($name);
        }
    } elseif (preg_match('/^billing_e2e_[0-9a-f]{8}$/', $name) === 1) {
        try {
            (new PDO((string) getenv('E2E_PG_DSN'), (string) getenv('E2E_PG_USER'), (string) getenv('E2E_PG_PASSWORD')))->exec("DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)");
        } catch (Throwable) {
            // pg.sh --prune drops idle billing_e2e_* databases as a backstop.
        }
    }

    @unlink($state);

    exit(0);
}
