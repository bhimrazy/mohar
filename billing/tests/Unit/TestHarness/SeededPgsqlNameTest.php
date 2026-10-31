<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
 * Seeded and template databases (TestCase::seededPgsqlName) must stay findable by scripts/pg.sh --drop, whose
 * drop_family matches ^<slug database>(_test_<n>)?(_<suffix>)?$, also when the slug's database name is as long as
 * pg.sh allows (55 bytes: a 42-character slug, or any longer slug after hashing).
 */

function spnSlugDatabase(string $slug): string
{
    $process = new Process(['bash', dirname(__DIR__, 4).'/scripts/pg.sh', '--db-name', $slug]);
    $process->setTimeout(10)->mustRun();

    return trim($process->getOutput());
}

function spnDropPattern(string $base): string
{
    $script = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/pg.sh');
    // The pattern in drop_family, so a change there fails this test instead of passing against a stale copy.
    expect($script)->toContain("datname ~ '^\${base}(_test_[0-9]+)?(_[A-Za-z0-9_]+)?\\\$'");

    return '/^'.preg_quote($base, '/').'(_test_[0-9]+)?(_[A-Za-z0-9_]+)?$/';
}

test('the template and seeded copies of a long slug keep its database prefix, so pg.sh --drop finds them', function (string $slug) {
    $base = spnSlugDatabase($slug);
    $names = [];

    foreach (['', '_test_1', '_test_2', '_test_99'] as $worker) {
        foreach (['template', 'currency_race_change_first_credit', 'currency_race_change_first_plain'] as $seeded) {
            $names[] = TestCase::seededPgsqlName($base.$worker, $seeded);
        }
    }

    expect(strlen($base))->toBe(55);

    foreach ($names as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(63)
            ->and($name)->toMatch(spnDropPattern($base));
    }

    expect(array_unique($names))->toHaveCount(count($names));
})->with([
    '42 characters' => [str_repeat('a', 42)],
    'hashed by pg.sh' => ['a-very-long-worktree-slug-that-pg-sh-has-to-shorten-with-a-hash'],
]);

test('a seeded name that fits is <database>_<name> unchanged', function () {
    expect(TestCase::seededPgsqlName('billing_test_orders_test_3', 'template'))->toBe('billing_test_orders_test_3_template');
});
