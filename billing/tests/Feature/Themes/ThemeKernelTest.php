<?php

use App\Modules\Themes\Support\ThemeManager;
use App\Modules\Themes\Support\ThemeStylesheet;
use App\Support\Themes\Contrast;
use App\Support\Themes\ThemePreset;
use App\Support\Themes\ThemeRegistry;
use App\Support\Themes\ThemeSchema;

it('ships four valid presets that all pass WCAG AA', function () {
    $registry = app(ThemeRegistry::class);

    expect(array_keys($registry->all()))->toBe(['calm', 'operator', 'warm', 'slate']);

    foreach ($registry->all() as $preset) {
        expect(ThemeSchema::validate($preset->tokens))->toBe([])
            ->and(ThemeSchema::contrastFailures($preset->tokens))->toBe([]);
    }
});

it('computes WCAG contrast ratios', function () {
    expect(Contrast::ratio('#000000', '#FFFFFF'))->toEqualWithDelta(21.0, 0.001)
        ->and(Contrast::ratio('#767676', '#FFFFFF'))->toEqualWithDelta(4.54, 0.01)
        ->and(Contrast::ratio('#FFFFFF', '#4F4BC4'))->toEqualWithDelta(Contrast::ratio('#4F4BC4', '#FFFFFF'), 0.0001);
});

it('lets modules register presets and rejects invalid or reserved ones', function () {
    $registry = app(ThemeRegistry::class);
    $tokens = app(ThemeManager::class)->presetDocument('calm');

    $registry->register(new ThemePreset('ocean', 'Ocean', $tokens, 'From a theme pack'));
    expect($registry->has('ocean'))->toBeTrue()
        ->and(app(ThemeManager::class)->find('ocean')?->name)->toBe('Ocean');

    $broken = $tokens;
    $broken['light']['card'] = 'red';
    expect(fn () => $registry->register(new ThemePreset('broken', 'Broken', $broken)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new ThemePreset('custom-x', 'Reserved', $tokens)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new ThemePreset('Bad Key', 'Bad', $tokens)))->toThrow(InvalidArgumentException::class);
});

it('drops anything outside the whitelist when rendering CSS, even unvalidated input', function () {
    $tokens = app(ThemeManager::class)->presetDocument('calm');
    $tokens['shared']['radius'] = '1rem;}body{display:none';
    $tokens['shared']['font-sans'] = "x'); background:url(evil)";
    $tokens['light']['background'] = 'url(javascript:alert(1))';
    $tokens['dark']['card'] = '#000000</style><script>';

    $css = app(ThemeStylesheet::class)->render($tokens);

    expect($css)->not->toContain('display:none')
        ->not->toContain('url(')
        ->not->toContain('<')
        ->not->toContain('--radius:')
        ->toContain('--card-foreground:#1A2133;')
        ->and(explode('html:root.dark', $css)[0])->not->toContain('--background:')
        ->and(substr_count($css, '{'))->toBe(2)
        ->and(substr_count($css, '}'))->toBe(2);
});

it('applies density presets on top of theme spacing', function () {
    $tokens = app(ThemeManager::class)->presetDocument('warm');
    $stylesheet = app(ThemeStylesheet::class);

    expect($stylesheet->render($tokens))->toContain('--cell-py:0.75rem;')
        ->and($stylesheet->render($tokens, 'compact'))->toContain('--cell-py:0.4375rem;');
});

it('publishes a JSON Schema that mirrors the token contract', function () {
    $schema = ThemeSchema::jsonSchema();

    expect($schema['properties']['tokens']['properties']['light']['required'])->toEqualCanonicalizing(array_keys(ThemeSchema::modeTypes()))
        ->and($schema['properties']['tokens']['properties']['shared']['additionalProperties'])->toBeFalse();
});
