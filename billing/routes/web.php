<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn (Request $request) => to_route($request->user()->is_staff ? 'admin.dashboard' : 'client.dashboard'))->name('dashboard');
});

if (app()->isLocal()) {
    // Design-system showcase (resources/js/pages/dev/components.tsx), used for visual review and screenshots.
    Route::inertia('dev/components', 'dev/components')->middleware('auth')->name('dev.components');
}

require __DIR__.'/settings.php';
