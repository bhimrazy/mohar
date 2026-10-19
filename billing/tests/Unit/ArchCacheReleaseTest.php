<?php

use Pest\Arch\Repositories\ObjectsRepository;

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

/*
 * releaseArchCache() resets a private static of an @internal plugin class. If an upgrade of
 * pestphp/pest-plugin-arch renames or removes it, this fails instead of the cache silently staying in memory.
 */
test('the arch plugin still keeps its cache in the private static ObjectsRepository::$instance', function () {
    $property = new ReflectionProperty(ObjectsRepository::class, 'instance');

    expect($property->isStatic())->toBeTrue()
        ->and($property->getType()?->allowsNull())->toBeTrue();

    $before = ObjectsRepository::getInstance();
    releaseArchCache();

    expect($property->getValue())->toBeNull()
        ->and(ObjectsRepository::getInstance())->not->toBe($before);
});
