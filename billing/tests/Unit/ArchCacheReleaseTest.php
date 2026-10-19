<?php

/*
 * The arch plugin never frees the source it parses; without this call one parallel worker keeps every Arch file's
 * cache until the end of the run (see releaseArchCache() in tests/Pest.php).
 */
test('every arch file releases the arch plugin cache after its rules', function (string $file) {
    expect(file_get_contents($file))->toContain('afterAll(fn () => releaseArchCache());');
})->with(fn (): array => array_combine(
    array_map(basename(...), $files = glob(dirname(__DIR__).'/Arch/*.php') ?: []),
    array_map(fn (string $file): array => [$file], $files),
));
