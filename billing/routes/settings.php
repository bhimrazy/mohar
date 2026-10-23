<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SetPasswordController;
use App\Http\Controllers\Settings\SetPasswordLinkController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // For a login without a password only (ADR 0018 §4): mails a link to the account's own address.
    Route::post('settings/password/set-link', SetPasswordLinkController::class)
        ->middleware('throttle:password-set-link')
        ->name('password.set-link');

    // The page the mail opens: a signed-out visitor signs in first and comes back through the intended URL.
    Route::get('settings/password/set/{token}', [SetPasswordController::class, 'show'])->name('password.set');
    Route::post('settings/password/set/{token}', [SetPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.set.store');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
