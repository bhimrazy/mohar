<?php

use App\Modules\Themes\Http\Controllers\Admin\AppearanceController;
use App\Modules\Themes\Http\Controllers\Admin\BrandAssetController;
use App\Modules\Themes\Http\Controllers\Admin\ThemeController;
use App\Modules\Themes\Http\Controllers\Admin\ThemePreferenceController;
use App\Modules\Themes\Support\BrandAssets;
use Illuminate\Support\Facades\Route;

Route::adminArea(function (): void {
    Route::get('appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::put('appearance', [AppearanceController::class, 'update'])->name('appearance.update');
    Route::delete('appearance/brand', [BrandAssetController::class, 'reset'])->name('appearance.brand.reset');
    Route::post('appearance/brand/{kind}', [BrandAssetController::class, 'store'])
        ->whereIn('kind', BrandAssets::KINDS)->name('appearance.brand.store');
    Route::delete('appearance/brand/{kind}', [BrandAssetController::class, 'destroy'])
        ->whereIn('kind', BrandAssets::KINDS)->name('appearance.brand.destroy');

    Route::get('appearance/themes/create', [ThemeController::class, 'create'])->name('appearance.themes.create');
    Route::post('appearance/themes', [ThemeController::class, 'store'])->name('appearance.themes.store');
    Route::post('appearance/themes/import', [ThemeController::class, 'import'])->name('appearance.themes.import');
    Route::get('appearance/themes/schema.json', [ThemeController::class, 'schema'])->name('appearance.themes.schema');
    Route::get('appearance/themes/{key}/export', [ThemeController::class, 'export'])
        ->where('key', '[a-z][a-z0-9-]{1,47}')->name('appearance.themes.export');
    Route::get('appearance/themes/{theme}/edit', [ThemeController::class, 'edit'])->name('appearance.themes.edit');
    Route::put('appearance/themes/{theme}', [ThemeController::class, 'update'])->name('appearance.themes.update');
    Route::patch('appearance/themes/{theme}/name', [ThemeController::class, 'rename'])->name('appearance.themes.rename');
    Route::delete('appearance/themes/{theme}', [ThemeController::class, 'destroy'])->name('appearance.themes.destroy');

    Route::put('appearance/preference', ThemePreferenceController::class)->name('appearance.preference');
});

Route::get('branding/{kind}', [BrandAssetController::class, 'show'])
    ->whereIn('kind', BrandAssets::KINDS)->name('branding.show');
