<?php

/*
 * Infra security review 2026-10-31, L6: DB_SSLMODE defaults to `prefer`, which never checks the server. The operator
 * docs tell a remote-database install to use verify-full with the provider's CA, and the CA reaches the PostgreSQL DSN.
 */

use Illuminate\Database\Connectors\PostgresConnector;

test('DB_SSLROOTCERT reaches the PostgreSQL DSN next to DB_SSLMODE, and is left out when unset', function () {
    $connection = config('database.connections.pgsql');
    $dsn = fn (array $config) => (fn () => $this->getDsn($config))->call(new PostgresConnector);

    expect($connection)->toHaveKey('sslrootcert')
        ->and($dsn([...$connection, 'sslmode' => 'verify-full', 'sslrootcert' => '/etc/ssl/db-ca.pem']))
        ->toContain(';sslmode=verify-full;sslrootcert=/etc/ssl/db-ca.pem')
        ->and($dsn([...$connection, 'sslrootcert' => null]))->not->toContain('sslrootcert');
});

test('the install guide tells a remote-database install to verify the server', function () {
    $install = (string) file_get_contents(base_path('docs/devops/install.md'));
    $cpanel = (string) file_get_contents(base_path('docs/devops/cpanel.md'));

    expect($install)->toContain('### Database over a network')
        ->toContain('DB_SSLMODE=verify-full')
        ->toContain('DB_SSLROOTCERT=')
        ->and($cpanel)->toContain('DB_SSLMODE=verify-full');
});
