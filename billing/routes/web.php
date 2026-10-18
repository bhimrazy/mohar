<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn (Request $request) => to_route($request->user()->is_staff ? 'admin.dashboard' : 'client.dashboard'))->name('dashboard');
});

if (app()->isLocal()) {
    // Design-system showcase (resources/js/pages/dev/components.tsx), used for visual review and screenshots.
    Route::inertia('dev/components', 'dev/components')->middleware('auth')->name('dev.components')->defaults('theme_area', 'admin');

    // Design-direction previews with sample data (docs/design/directions.md); switch themes in the header.
    Route::inertia('dev/dashboard-preview', 'dev/dashboard-preview')->middleware('auth')->name('dev.dashboard-preview')->defaults('theme_area', 'admin');
    Route::inertia('dev/invoice-preview', 'dev/invoice-preview')->middleware('auth')->name('dev.invoice-preview')->defaults('theme_area', 'admin');
    Route::inertia('dev/client-preview', 'dev/client-preview')->middleware('auth')->name('dev.client-preview')->defaults('theme_area', 'client');
}

require __DIR__.'/settings.php';
