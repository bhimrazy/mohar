<?php

use App\Support\Localization\Catalogue;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

function catalogueDirectory(array $files): string
{
    $directory = sys_get_temp_dir().'/mohar-lang-'.bin2hex(random_bytes(4));
    mkdir($directory);

    foreach ($files as $name => $messages) {
        file_put_contents($directory.'/'.$name.'.json', json_encode($messages, JSON_UNESCAPED_UNICODE));
    }

    return $directory;
}

afterEach(function () {
    @unlink(Catalogue::cachePath());
    Catalogue::flush();
});

beforeEach(function () {
    Catalogue::flush();
    actingAsStaff(['*']);
});

test('English pages share an empty catalogue and the en locale', function () {
    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'en')
        ->where('translations', []));
});

test('a non-English locale shares its catalogue and the BCP 47 tag, sorted', function () {
    app('translator')->addJsonPath(catalogueDirectory(['ne' => ['Total' => 'जम्मा', 'Due' => 'बाँकी']]));
    app()->setLocale('ne_NP');
    app('translator')->addJsonPath(catalogueDirectory(['ne_NP' => ['Total' => 'जम्मा']]));

    $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'ne-NP')
        ->where('translations', ['Total' => 'जम्मा']));
});

test('the catalogue is a once prop keyed by locale and version, so later visits skip it', function () {
    app('translator')->addJsonPath(catalogueDirectory(['ne' => ['Total' => 'जम्मा']]));
    app()->setLocale('ne');
    $key = 'i18n:ne:'.Catalogue::version('ne');

    $first = $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('translations', ['Total' => 'जम्मा']));
    expect($first->viewData('page')['onceProps'])->toHaveKey($key)
        ->and($first->viewData('page')['onceProps'][$key]['prop'])->toBe('translations');

    $again = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $first->viewData('page')['version'], 'X-Inertia-Except-Once-Props' => $key])->get(route('admin.dashboard'));
    expect($again->json('props'))->not->toHaveKey('translations');
});

test('the version changes only when the catalogue content changes', function () {
    app('translator')->addJsonPath(catalogueDirectory(['ne' => ['A' => 'क']]));
    $before = Catalogue::version('ne');
    expect(Catalogue::version('ne'))->toBe($before);

    Catalogue::flush();
    app('translator')->addJsonPath(catalogueDirectory(['ne' => ['A' => 'ख']]));
    expect(Catalogue::version('ne'))->not->toBe($before);
});

test('a locale without a file has an empty catalogue and __() falls back to the key', function () {
    expect(Catalogue::messages('xx'))->toBe([])
        ->and(__('Payment received. Thank you.'))->toBe('Payment received. Thank you.');
});

test('trans_choice gives the results in the shared plural fixture', function () {
    $cases = json_decode(file_get_contents(base_path('tests/Fixtures/i18n/plurals.json')), true, flags: JSON_THROW_ON_ERROR);

    $lines = [];
    foreach ($cases as $case) {
        $lines[$case['locale']][$case['line']] = $case['line'];
    }

    app('translator')->addJsonPath(catalogueDirectory($lines));
    app('translator')->setLoaded([]);

    foreach ($cases as $case) {
        expect(trans_choice($case['line'], $case['count'], $case['replace'], $case['locale']))
            ->toBe($case['expected'], "{$case['locale']} {$case['line']} {$case['count']}");
    }
});

test('optimize caches the catalogue, serves it from the cache file and optimize:clear drops it', function () {
    $directory = catalogueDirectory(['ne' => ['Total' => 'जम्मा']]);
    app('translator')->addJsonPath($directory);

    Artisan::call('i18n:cache');
    expect(Catalogue::cachePath())->toBeFile();
    $cached = Catalogue::version('ne');

    // Changing the file alone does not change what the cache serves ...
    file_put_contents($directory.'/ne.json', json_encode(['Total' => 'कुल'], JSON_UNESCAPED_UNICODE));
    app('translator')->setLoaded([]);
    Catalogue::flush();
    expect(Catalogue::version('ne'))->toBe($cached)->and(Catalogue::messages('ne'))->toBe(['Total' => 'जम्मा']);

    // ... and `optimize` rebuilds it with the new version.
    Artisan::call('optimize');
    expect(Catalogue::version('ne'))->not->toBe($cached)->and(Catalogue::messages('ne'))->toBe(['Total' => 'कुल']);

    Artisan::call('optimize:clear');
    expect(Catalogue::cachePath())->not->toBeFile();
});
