<?php

use App\Modules\Core\Http\Controllers\Admin\ClientController;
use App\Modules\Core\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Modules\Core\Http\Controllers\Client\AccountController;
use App\Modules\Core\Http\Controllers\Client\DashboardController as ClientDashboardController;
use Illuminate\Support\Facades\Route;

Route::adminArea(function (): void {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::resource('clients', ClientController::class)->except('destroy');
});

Route::clientArea(function (): void {
    Route::get('/', ClientDashboardController::class)->name('dashboard');
    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account', [AccountController::class, 'update'])->name('account.update');
    Route::post('account/switch', [AccountController::class, 'switch'])->name('account.switch');
});
