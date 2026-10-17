<?php

use App\Modules\Themes\Http\Controllers\Admin\AppearanceController;
use App\Modules\Themes\Http\Controllers\Admin\BrandAssetController;
use App\Modules\Themes\Http\Controllers\Admin\ThemeController;
use App\Modules\Themes\Http\Controllers\Admin\ThemePreferenceController;
use App\Modules\Themes\Support\BrandAssets;
use Illuminate\Support\Facades\Route;

Route::adminArea(function (): void {
    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('settings.appearance');
    Route::put('settings/appearance', [AppearanceController::class, 'update'])->name('settings.appearance.update');
    Route::delete('settings/appearance/brand', [BrandAssetController::class, 'reset'])->name('settings.appearance.brand.reset');
    Route::post('settings/appearance/brand/{kind}', [BrandAssetController::class, 'store'])
        ->whereIn('kind', BrandAssets::KINDS)->name('settings.appearance.brand.store');
    Route::delete('settings/appearance/brand/{kind}', [BrandAssetController::class, 'destroy'])
        ->whereIn('kind', BrandAssets::KINDS)->name('settings.appearance.brand.destroy');

    Route::get('settings/themes/create', [ThemeController::class, 'create'])->name('settings.themes.create');
    Route::post('settings/themes', [ThemeController::class, 'store'])->name('settings.themes.store');
    Route::post('settings/themes/import', [ThemeController::class, 'import'])->name('settings.themes.import');
    Route::get('settings/themes/schema.json', [ThemeController::class, 'schema'])->name('settings.themes.schema');
    Route::get('settings/themes/{key}/export', [ThemeController::class, 'export'])
        ->where('key', '[a-z][a-z0-9-]{1,47}')->name('settings.themes.export');
    Route::get('settings/themes/{theme}/edit', [ThemeController::class, 'edit'])->name('settings.themes.edit');
    Route::put('settings/themes/{theme}', [ThemeController::class, 'update'])->name('settings.themes.update');
    Route::patch('settings/themes/{theme}/name', [ThemeController::class, 'rename'])->name('settings.themes.rename');
    Route::delete('settings/themes/{theme}', [ThemeController::class, 'destroy'])->name('settings.themes.destroy');

    Route::put('appearance/preference', ThemePreferenceController::class)->name('appearance.preference');
});

Route::get('branding/{kind}', [BrandAssetController::class, 'show'])
    ->whereIn('kind', BrandAssets::KINDS)->name('branding.show');
