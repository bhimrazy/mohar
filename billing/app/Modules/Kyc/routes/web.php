<?php

use App\Modules\Kyc\Http\Controllers\Admin\ReviewController;
use App\Modules\Kyc\Http\Controllers\Client\VerificationController;
use Illuminate\Support\Facades\Route;

Route::clientArea(function (): void {
    Route::prefix('verification')->name('kyc.')->group(function (): void {
        Route::get('/', [VerificationController::class, 'show'])->name('show');
        Route::put('phone', [VerificationController::class, 'phone'])->middleware('throttle:10,1')->name('phone');
        Route::post('documents', [VerificationController::class, 'document'])->middleware('throttle:10,1')->name('documents.store');
    });
});

Route::adminArea(function (): void {
    Route::prefix('kyc')->name('kyc.')->group(function (): void {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::get('clients/{client}', [ReviewController::class, 'show'])->whereNumber('client')->name('show');
        Route::post('clients/{client}/unlock', [ReviewController::class, 'unlock'])->whereNumber('client')->middleware('password.recent')->name('unlock');
        Route::get('documents/{document}/{side}', [ReviewController::class, 'image'])->whereNumber('document')->whereIn('side', ['front', 'back'])
            ->middleware(['signed', 'password.recent'])->name('image');
        Route::post('documents/{document}/decision', [ReviewController::class, 'decide'])->whereNumber('document')->middleware('password.recent')->name('decide');
    });
});
