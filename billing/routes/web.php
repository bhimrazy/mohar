<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn (Request $request) => to_route($request->user()->is_staff ? 'admin.dashboard' : 'client.dashboard'))->name('dashboard');
});

require __DIR__.'/settings.php';
