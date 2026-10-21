<?php

use App\Modules\Kyc\Http\Controllers\Client\VerificationController;
use App\Modules\Kyc\Http\Middleware\EnsureKycEnabled;
use Illuminate\Support\Facades\Route;

Route::clientArea(function (): void {
    Route::prefix('verification')->name('kyc.')->middleware(EnsureKycEnabled::class)->group(function (): void {
        Route::get('/', [VerificationController::class, 'show'])->name('show');
        Route::put('phone', [VerificationController::class, 'phone'])->middleware('throttle:10,1')->name('phone');
        Route::post('documents', [VerificationController::class, 'document'])->middleware('throttle:10,1')->name('documents.store');
    });
});
