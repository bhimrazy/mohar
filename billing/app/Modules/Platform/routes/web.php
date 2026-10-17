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
use App\Modules\Platform\Http\Middleware\RequireRecentPasswordConfirmation;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::name('platform.')->group(function (): void {
    $confirmPage = 'password.confirm:password.confirm,'.RequireRecentPasswordConfirmation::timeout();

    Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');

    // Staff and roles are sensitive: pages ask for the password first, mutations require a recent confirmation.
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::middleware($confirmPage)->group(function (): void {
        Route::get('staff/create', [StaffController::class, 'create'])->name('staff.create');
        Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])->name('staff.edit');
        Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    });
    Route::middleware('password.recent')->group(function (): void {
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::post('staff/{staff}/reset-two-factor', [StaffController::class, 'resetTwoFactor'])->name('staff.reset-two-factor');
        Route::post('staff-invitations/{invitation}/resend', [StaffInvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('staff-invitations/{invitation}', [StaffInvitationController::class, 'destroy'])->name('invitations.destroy');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::resource('currencies', CurrencyController::class)->except('show');
    Route::post('currencies/{currency}/default', [CurrencyController::class, 'makeDefault'])->name('currencies.default');
    Route::resource('client-groups', ClientGroupController::class)->except('show');

    Route::get('settings/{section?}', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/{section}', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    Route::get('automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::post('automation/run', [AutomationController::class, 'run'])->middleware('throttle:6,1')->name('automation.run');

    Route::prefix('clients/{client}')->name('clients.')->scopeBindings()->group(function (): void {
        Route::middleware('password.recent')->group(function (): void {
            Route::post('close', [ClientLifecycleController::class, 'close'])->name('close');
            Route::post('reopen', [ClientLifecycleController::class, 'reopen'])->name('reopen');
            Route::post('merge', [ClientLifecycleController::class, 'merge'])->name('merge');
            Route::post('impersonate', [ImpersonationController::class, 'start'])->name('impersonate');
            Route::post('users/{user}/reset-two-factor', [ClientUserController::class, 'resetTwoFactor'])->name('users.reset-two-factor')->withoutScopedBindings();
        });
        Route::get('merge-preview', [ClientLifecycleController::class, 'mergePreview'])->name('merge-preview');

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
