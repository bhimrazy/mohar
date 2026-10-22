<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Platform\Http\Controllers\Admin\ActivityLogController;
use App\Modules\Platform\Http\Controllers\Admin\AutomationController;
use App\Modules\Platform\Http\Controllers\Admin\ClientContactController;
use App\Modules\Platform\Http\Controllers\Admin\ClientGroupController;
use App\Modules\Platform\Http\Controllers\Admin\ClientLifecycleController;
use App\Modules\Platform\Http\Controllers\Admin\ClientUserController;
use App\Modules\Platform\Http\Controllers\Admin\CurrencyController;
use App\Modules\Platform\Http\Controllers\Admin\ExchangeRateController;
use App\Modules\Platform\Http\Controllers\Admin\ImpersonationController;
use App\Modules\Platform\Http\Controllers\Admin\PriceConversionController;
use App\Modules\Platform\Http\Controllers\Admin\RoleController;
use App\Modules\Platform\Http\Controllers\Admin\SearchController;
use App\Modules\Platform\Http\Controllers\Admin\SettingsController;
use App\Modules\Platform\Http\Controllers\Admin\StaffController;
use App\Modules\Platform\Http\Controllers\Admin\StaffInvitationController;
use App\Modules\Platform\Http\Controllers\Public\ClientInvitationController;
use App\Modules\Platform\Http\Controllers\Public\StaffInvitationController as AcceptInvitationController;
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
    // Exchange rates move converted prices: sensitive permission plus a recent password for every change.
    Route::get('currencies/conversion', [PriceConversionController::class, 'show'])->name('currencies.conversion');
    Route::middleware('password.recent')->group(function (): void {
        Route::post('currencies/conversion/{converter}', [PriceConversionController::class, 'apply'])->where('converter', '[a-z][a-z0-9_]*')->name('currencies.conversion.apply');
        Route::post('currencies/rates/refresh', [ExchangeRateController::class, 'refresh'])->middleware('throttle:6,1')->name('currencies.rates.refresh');
        Route::post('currencies/reviews/{review}/approve', [ExchangeRateController::class, 'approve'])->name('currencies.reviews.approve');
        Route::post('currencies/reviews/{review}/reject', [ExchangeRateController::class, 'reject'])->name('currencies.reviews.reject');
        Route::put('currencies/{currency}/rates', [ExchangeRateController::class, 'update'])->name('currencies.rates.update');
        Route::post('currencies/{currency}/default', [CurrencyController::class, 'makeDefault'])->name('currencies.default');
        // Creating sets a starting rate; deleting and re-creating would otherwise reset one.
        Route::post('currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::delete('currencies/{currency}', [CurrencyController::class, 'destroy'])->name('currencies.destroy');
    });
    Route::resource('currencies', CurrencyController::class)->except(['show', 'store', 'destroy']);
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

            // Client logins: adding a login or changing the owner grants persistent account access.
            Route::post('users', [ClientUserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [ClientUserController::class, 'update'])->name('users.update')->withoutScopedBindings();
            Route::post('users/{user}/owner', [ClientUserController::class, 'transfer'])->name('users.transfer')->withoutScopedBindings();
            Route::delete('users/{user}', [ClientUserController::class, 'destroy'])->name('users.destroy')->withoutScopedBindings();
            Route::post('users/{user}/invitation', [ClientUserController::class, 'resendInvitation'])->name('users.invitation')->withoutScopedBindings();
            Route::post('users/{user}/password-reset', [ClientUserController::class, 'sendPasswordReset'])->middleware('throttle:6,1')->name('users.password-reset')->withoutScopedBindings();
        });
        Route::get('merge-preview', [ClientLifecycleController::class, 'mergePreview'])->name('merge-preview');

        Route::post('contacts', [ClientContactController::class, 'store'])->name('contacts.store');
        Route::put('contacts/{contact}', [ClientContactController::class, 'update'])->name('contacts.update');
        Route::delete('contacts/{contact}', [ClientContactController::class, 'destroy'])->name('contacts.destroy');

    });

    Route::get('impersonation/{impersonation}', [ImpersonationController::class, 'enter'])->middleware('signed')->name('impersonation.enter');
}));

Route::middleware('auth')->post('impersonation/leave', [ImpersonationController::class, 'leave'])->name('platform.impersonation.leave');

Route::middleware('guest')->prefix('account-invitation')->name('platform.client-invitations.')->group(function (): void {
    Route::get('{token}', [ClientInvitationController::class, 'show'])->middleware('throttle:30,1')->name('show');
    Route::post('{token}', [ClientInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('accept');
});

Route::middleware('guest')->prefix('staff-invitation')->name('platform.invitations.')->group(function (): void {
    Route::get('{token}', [AcceptInvitationController::class, 'show'])->middleware('throttle:30,1')->name('show');
    Route::post('{token}', [AcceptInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('accept');
});
