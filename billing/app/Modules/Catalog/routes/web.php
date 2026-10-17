<?php

use App\Modules\Catalog\Http\Controllers\Admin\AddonController;
use App\Modules\Catalog\Http\Controllers\Admin\ConfigOptionController;
use App\Modules\Catalog\Http\Controllers\Admin\ConfigOptionGroupController;
use App\Modules\Catalog\Http\Controllers\Admin\ConfigOptionValueController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductCustomFieldController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductGroupController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductSettingsController;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::prefix('catalog')->name('catalog.')->group(function (): void {
    Route::post('groups/reorder', [ProductGroupController::class, 'reorder'])->name('groups.reorder');
    Route::resource('groups', ProductGroupController::class)->except('show');

    Route::resource('products', ProductController::class)->except('show');
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
    Route::put('products/{product}/pricing', [ProductSettingsController::class, 'pricing'])->name('products.pricing.update');
    Route::put('products/{product}/config-options', [ProductSettingsController::class, 'configOptions'])->name('products.config-options.update');
    Route::put('products/{product}/addons', [ProductSettingsController::class, 'addons'])->name('products.addons.update');
    Route::put('products/{product}/upgrades', [ProductSettingsController::class, 'upgrades'])->name('products.upgrades.update');
    Route::put('products/{product}/provisioning', [ProductSettingsController::class, 'provisioning'])->name('products.provisioning.update');
    Route::resource('products.fields', ProductCustomFieldController::class)->only(['store', 'update', 'destroy']);

    Route::resource('option-groups', ConfigOptionGroupController::class)->except('show');
    Route::resource('option-groups.options', ConfigOptionController::class)->only(['store', 'update', 'destroy'])->shallow();
    Route::resource('options.values', ConfigOptionValueController::class)->only(['store', 'update', 'destroy'])->shallow();

    Route::resource('addons', AddonController::class)->except('show');
}));
