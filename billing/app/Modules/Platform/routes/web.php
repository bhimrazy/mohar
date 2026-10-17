<?php

use App\Modules\Platform\Http\Controllers\Admin\ActivityLogController;
use App\Modules\Platform\Http\Controllers\Admin\AutomationController;
use App\Modules\Platform\Http\Controllers\Admin\ClientContactController;
use App\Modules\Platform\Http\Controllers\Admin\ClientGroupController;
use App\Modules\Platform\Http\Controllers\Admin\ClientLifecycleController;
use App\Modules\Platform\Http\Controllers\Admin\ClientUserController;
use App\Modules\Platform\Http\Controllers\Admin\CurrencyController;
use App\Modules\Platform\Http\Controllers\Admin\ImpersonationController;
use App\Modules\Platform\Http\Controllers\Admin\RoleController;
use App\Modules\Platform\Http\Controllers\Admin\SearchController;
use App\Modules\Platform\Http\Controllers\Admin\SettingsController;
use App\Modules\Platform\Http\Controllers\Admin\StaffController;
use App\Modules\Platform\Http\Controllers\Admin\StaffInvitationController;
use App\Modules\Platform\Http\Controllers\Public\StaffInvitationController as AcceptInvitationController;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::name('platform.')->group(function (): void {
    Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');

    Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'staff']);
    Route::post('staff/{staff}/reset-two-factor', [StaffController::class, 'resetTwoFactor'])->name('staff.reset-two-factor');
    Route::post('staff-invitations/{invitation}/resend', [StaffInvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('staff-invitations/{invitation}', [StaffInvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::resource('roles', RoleController::class)->except('show');
    Route::resource('currencies', CurrencyController::class)->except('show');
    Route::post('currencies/{currency}/default', [CurrencyController::class, 'makeDefault'])->name('currencies.default');
    Route::resource('client-groups', ClientGroupController::class)->except('show');

    Route::get('settings/{section?}', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/{section}', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    Route::get('automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::post('automation/run', [AutomationController::class, 'run'])->middleware('throttle:6,1')->name('automation.run');

    Route::prefix('clients/{client}')->name('clients.')->scopeBindings()->group(function (): void {
        Route::post('close', [ClientLifecycleController::class, 'close'])->name('close');
        Route::post('reopen', [ClientLifecycleController::class, 'reopen'])->name('reopen');
        Route::get('merge-preview', [ClientLifecycleController::class, 'mergePreview'])->name('merge-preview');
        Route::post('merge', [ClientLifecycleController::class, 'merge'])->name('merge');
        Route::post('impersonate', [ImpersonationController::class, 'start'])->name('impersonate');

        Route::post('contacts', [ClientContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ClientContactController::class, 'update'])->name('contacts.update');
        Route::delete('contacts/{contact}', [ClientContactController::class, 'destroy'])->name('contacts.destroy');

        Route::post('users', [ClientUserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [ClientUserController::class, 'update'])->name('users.update')->withoutScopedBindings();
        Route::post('users/{user}/owner', [ClientUserController::class, 'transfer'])->name('users.transfer')->withoutScopedBindings();
        Route::delete('users/{user}', [ClientUserController::class, 'destroy'])->name('users.destroy')->withoutScopedBindings();
    });

    Route::get('impersonation/{impersonation}', [ImpersonationController::class, 'enter'])->middleware('signed')->name('impersonation.enter');
}));

Route::middleware('auth')->post('impersonation/leave', [ImpersonationController::class, 'leave'])->name('platform.impersonation.leave');

Route::middleware('guest')->prefix('staff-invitation')->name('platform.invitations.')->group(function (): void {
    Route::get('{token}', [AcceptInvitationController::class, 'show'])->name('show');
    Route::post('{token}', [AcceptInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('accept');
});
