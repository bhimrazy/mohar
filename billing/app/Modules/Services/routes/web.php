<?php

use App\Modules\Services\Http\Controllers\Admin\CancellationRequestController;
use App\Modules\Services\Http\Controllers\Admin\ModuleLogController;
use App\Modules\Services\Http\Controllers\Admin\ServerController;
use App\Modules\Services\Http\Controllers\Admin\ServerGroupController;
use App\Modules\Services\Http\Controllers\Admin\ServerImportController;
use App\Modules\Services\Http\Controllers\Admin\ServiceActionController;
use App\Modules\Services\Http\Controllers\Admin\ServiceController;
use App\Modules\Services\Http\Controllers\Client\ServiceActionController as ClientServiceActionController;
use App\Modules\Services\Http\Controllers\Client\ServiceController as ClientServiceController;
use Illuminate\Support\Facades\Route;

Route::adminArea(function (): void {
    Route::prefix('services')->name('services.')->group(function (): void {
        Route::get('/', [ServiceController::class, 'index'])->name('index');

        // Server credentials are sensitive: changing them needs a recent password confirmation.
        Route::resource('servers', ServerController::class)->only(['index', 'create', 'edit']);
        Route::get('servers/{server}/import', [ServerImportController::class, 'show'])->name('servers.import.show');
        Route::get('servers/{server}/import/clients', [ServerImportController::class, 'clients'])->middleware('throttle:60,1')->name('servers.import.clients');
        Route::get('servers/{server}/import/review', [ServerImportController::class, 'review'])->name('servers.import.review');
        Route::middleware('password.recent')->group(function (): void {
            Route::resource('servers', ServerController::class)->only(['store', 'update', 'destroy']);
            Route::post('servers/{server}/test', [ServerController::class, 'test'])->middleware('throttle:6,1')->name('servers.test');
            Route::post('servers/{server}/trust-certificate', [ServerController::class, 'trustCertificate'])->name('servers.trust-certificate');
            Route::delete('servers/{server}/trust-certificate', [ServerController::class, 'forgetCertificate'])->name('servers.forget-certificate');
            // Group membership decides where new accounts are provisioned, so it is guarded like the servers.
            Route::resource('server-groups', ServerGroupController::class)->only(['store', 'update', 'destroy']);
            Route::post('servers/{server}/import/fetch', [ServerImportController::class, 'fetch'])->middleware('throttle:6,1')->name('servers.import.fetch');
            Route::post('servers/{server}/import/match', [ServerImportController::class, 'rematch'])->middleware('throttle:6,1')->name('servers.import.rematch');
            Route::put('servers/{server}/import/selections', [ServerImportController::class, 'select'])->middleware('throttle:services-import-select')->name('servers.import.select');
            Route::post('servers/{server}/import/apply', [ServerImportController::class, 'apply'])->middleware('throttle:services-import-apply')->name('servers.import.apply');
        });
        Route::resource('server-groups', ServerGroupController::class)->only(['create', 'edit']);

        Route::get('cancellations', [CancellationRequestController::class, 'index'])->name('cancellations.index');
        Route::post('cancellations/{cancellationRequest}/approve', [CancellationRequestController::class, 'approve'])->name('cancellations.approve');
        Route::post('cancellations/{cancellationRequest}/void', [CancellationRequestController::class, 'void'])->name('cancellations.void');

        Route::get('module-log', [ModuleLogController::class, 'index'])->name('module-log.index');
        Route::post('module-log/queue/{item}/retry', [ModuleLogController::class, 'retry'])->name('module-log.retry');
        Route::post('module-log/queue/{item}/resolve', [ModuleLogController::class, 'resolve'])->name('module-log.resolve');
        Route::post('module-log/queue/{item}/dismiss', [ModuleLogController::class, 'dismiss'])->name('module-log.dismiss');
        Route::post('module-log/queue/{item}/check', [ModuleLogController::class, 'check'])->middleware('throttle:6,1')->name('module-log.check');

        Route::prefix('{service}')->whereNumber('service')->name('services.')->group(function (): void {
            Route::get('/', [ServiceController::class, 'show'])->name('show');
            Route::get('edit', [ServiceController::class, 'edit'])->name('edit');
            Route::post('credentials', [ServiceController::class, 'credentials'])->middleware('password.recent')->name('credentials');
            Route::put('/', [ServiceController::class, 'update'])->name('update');
            Route::delete('/', [ServiceController::class, 'destroy'])->name('destroy');
            Route::post('commands', [ServiceActionController::class, 'command'])->name('command');
            Route::post('status', [ServiceActionController::class, 'status'])->name('status');
            Route::post('change', [ServiceActionController::class, 'change'])->name('change');
            Route::post('cancellation', [ServiceActionController::class, 'cancel'])->name('cancel');
            Route::post('addons', [ServiceActionController::class, 'addAddon'])->name('addons.store');
        });
    });
});

Route::clientArea(function (): void {
    Route::prefix('services')->name('services.')->group(function (): void {
        Route::get('/', [ClientServiceController::class, 'index'])->name('index');
        Route::get('{service}', [ClientServiceController::class, 'show'])->whereNumber('service')->name('show');
        Route::post('{service}/cancel', [ClientServiceActionController::class, 'cancel'])->whereNumber('service')->name('cancel');
        Route::post('{service}/change', [ClientServiceActionController::class, 'change'])->whereNumber('service')->name('change');
        // Each change is a synchronous call to the shared panel server (security review 2026-10-19, L3).
        Route::post('{service}/password', [ClientServiceActionController::class, 'password'])->whereNumber('service')->middleware('throttle:6,1')->name('password');
        // Shows the stored password (#44): step-up first, then a per-login limit across all services.
        Route::post('{service}/password/reveal', [ClientServiceActionController::class, 'revealPassword'])->whereNumber('service')->middleware(['password.recent', 'throttle:services-password-reveal'])->name('password.reveal');
    });
});
