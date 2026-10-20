<?php

use App\Modules\Migration\Http\Controllers\Admin\ClientImportController;
use Illuminate\Support\Facades\Route;

Route::adminArea(function (): void {
    Route::prefix('migration')->name('migration.')->group(function (): void {
        Route::get('imports', [ClientImportController::class, 'index'])->name('imports.index');
        Route::get('imports/{run}', [ClientImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{run}/errors', [ClientImportController::class, 'errors'])->middleware('throttle:10,1')->name('imports.errors');

        // Uploads hold client data and checks read every client: both need a recent password.
        Route::middleware('password.recent')->group(function (): void {
            Route::post('imports', [ClientImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
            Route::put('imports/{run}/mapping', [ClientImportController::class, 'mapping'])->middleware('throttle:20,1')->name('imports.mapping');
            Route::put('imports/{run}/rows', [ClientImportController::class, 'decide'])->middleware('throttle:120,1')->name('imports.decide');
            Route::post('imports/{run}/accept', [ClientImportController::class, 'accept'])->middleware('throttle:20,1')->name('imports.accept');
            Route::post('imports/{run}/skip-undecided', [ClientImportController::class, 'skipUndecided'])->middleware('throttle:20,1')->name('imports.skip-undecided');
            Route::post('imports/{run}/apply', [ClientImportController::class, 'apply'])->middleware('throttle:6,1')->name('imports.apply');
            Route::post('imports/{run}/resume', [ClientImportController::class, 'resume'])->middleware('throttle:10,1')->name('imports.resume');
        });
    });
});
