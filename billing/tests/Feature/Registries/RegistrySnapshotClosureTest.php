<?php

use App\Support\Seo\SitemapRegistry;
use Tests\Support\Registries\RegistrySnapshot;

function snapshotWithSitemapEntry(Closure $source): array
{
    app(SitemapRegistry::class)->register('zz_snapshot_probe', $source, replace: true);

    return RegistrySnapshot::capture('/zz_snapshot_probe/', 'App\Modules\ZzProbe')['sitemap'];
}

test('a changed closure body changes the snapshot', function () {
    $original = fn () => [['loc' => '/a', 'lastmod' => null]];
    $edited = fn () => [['loc' => '/b', 'lastmod' => null]];

    $before = snapshotWithSitemapEntry($original);
    $after = snapshotWithSitemapEntry($edited);

    expect($before)->not->toBeEmpty()->and($after)->not->toBe($before);
});

test('reformatting or commenting a closure does not change the snapshot', function () {
    $compact = fn () => [['loc' => '/a', 'lastmod' => null]];
    $spread = fn () => [
        // a comment
        ['loc' => '/a', 'lastmod' => null],
    ];

    expect(snapshotWithSitemapEntry($spread))->toBe(snapshotWithSitemapEntry($compact));
});
