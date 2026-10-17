<?php

use App\Modules\Services\Http\Controllers\Admin\CancellationRequestController;
use App\Modules\Services\Http\Controllers\Admin\ModuleLogController;
use App\Modules\Services\Http\Controllers\Admin\ServerController;
use App\Modules\Services\Http\Controllers\Admin\ServerGroupController;
use App\Modules\Services\Http\Controllers\Admin\ServiceActionController;
use App\Modules\Services\Http\Controllers\Admin\ServiceController;
use App\Modules\Services\Http\Controllers\Client\ServiceActionController as ClientServiceActionController;
use App\Modules\Services\Http\Controllers\Client\ServiceController as ClientServiceController;
use Illuminate\Support\Facades\Route;

Route::adminArea(function (): void {
    Route::prefix('services')->name('services.')->group(function (): void {
        Route::get('/', [ServiceController::class, 'index'])->name('index');

        Route::resource('servers', ServerController::class)->except('show');
        Route::post('servers/{server}/test', [ServerController::class, 'test'])->name('servers.test');
        Route::resource('server-groups', ServerGroupController::class)->except(['index', 'show']);

        Route::get('cancellations', [CancellationRequestController::class, 'index'])->name('cancellations.index');
        Route::post('cancellations/{cancellationRequest}/approve', [CancellationRequestController::class, 'approve'])->name('cancellations.approve');
        Route::post('cancellations/{cancellationRequest}/void', [CancellationRequestController::class, 'void'])->name('cancellations.void');

        Route::get('module-log', [ModuleLogController::class, 'index'])->name('module-log.index');
        Route::post('module-log/queue/{item}/retry', [ModuleLogController::class, 'retry'])->name('module-log.retry');
        Route::post('module-log/queue/{item}/resolve', [ModuleLogController::class, 'resolve'])->name('module-log.resolve');
        Route::post('module-log/queue/{item}/dismiss', [ModuleLogController::class, 'dismiss'])->name('module-log.dismiss');

        Route::prefix('{service}')->whereNumber('service')->name('services.')->group(function (): void {
            Route::get('/', [ServiceController::class, 'show'])->name('show');
            Route::get('edit', [ServiceController::class, 'edit'])->name('edit');
            Route::post('credentials', [ServiceController::class, 'credentials'])->name('credentials');
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
        Route::post('{service}/password', [ClientServiceActionController::class, 'password'])->whereNumber('service')->name('password');
    });
});
