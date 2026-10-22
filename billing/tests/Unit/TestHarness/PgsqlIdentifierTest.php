<?php

use Tests\TestCase;

/*
 * The 63-byte rule for derived PostgreSQL database names (race tests, #285). scripts/pg.sh applies the same rule
 * (name_fit); the vector below was computed with sha256sum, so a change on either side fails this test.
 */

test('a name that fits the PostgreSQL limit is kept unchanged', function () {
    expect(TestCase::pgsqlIdentifier('billing_test_sec_periodic_test_2'))->toBe('billing_test_sec_periodic_test_2');
});

test('a long name is shortened to 63 bytes and matches the pg.sh rule', function () {
    $name = 'billing_test_pg_slug_limit_repro_with_a_long_slug_test_1_currency_race_change_first_plain';

    expect(TestCase::pgsqlIdentifier($name))
        ->toBe('billing_test_pg_slug_limit_repro_with_a_long_slug_test_504b23fb')
        ->and(strlen(TestCase::pgsqlIdentifier($name)))->toBe(63);
});

test('long names that differ only at the end stay distinct', function () {
    $prefix = 'billing_test_pg_slug_limit_repro_with_a_long_slug_test_1_currency_race_';

    expect(TestCase::pgsqlIdentifier($prefix.'change_first_plain'))
        ->not->toBe(TestCase::pgsqlIdentifier($prefix.'change_first_credit'));
});
