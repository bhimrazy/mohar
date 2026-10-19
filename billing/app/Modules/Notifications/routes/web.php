<?php

use App\Modules\Notifications\Http\Controllers\Admin\ClientEmailController;
use App\Modules\Notifications\Http\Controllers\Admin\EmailLogController;
use App\Modules\Notifications\Http\Controllers\Admin\EmailTemplateController;
use App\Modules\Notifications\Http\Controllers\Admin\StaffSubscriptionController;
use App\Modules\Notifications\Http\Controllers\Admin\WebhookEndpointController;
use App\Modules\Notifications\Http\Controllers\Client\EmailHistoryController;
use App\Modules\Notifications\Http\Controllers\Client\EmailPreferenceController;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::prefix('notifications')->name('notifications.')->group(function (): void {
    // Templates: reading and previews for template viewers; changes are sensitive (CC/BCC, sender) → recent password.
    Route::get('templates', [EmailTemplateController::class, 'index'])->name('templates.index');
    Route::get('templates/create', [EmailTemplateController::class, 'create'])->name('templates.create');
    Route::get('templates/{template}/edit', [EmailTemplateController::class, 'edit'])->name('templates.edit');
    Route::post('templates/{template}/preview', [EmailTemplateController::class, 'preview'])->middleware('throttle:60,1')->name('templates.preview');
    Route::post('templates/{template}/test', [EmailTemplateController::class, 'test'])->middleware('throttle:10,1')->name('templates.test');
    Route::middleware('password.recent')->group(function (): void {
        Route::post('templates', [EmailTemplateController::class, 'store'])->name('templates.store');
        Route::put('templates/{template}', [EmailTemplateController::class, 'update'])->name('templates.update');
        Route::delete('templates/{template}', [EmailTemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('templates/{template}/toggle', [EmailTemplateController::class, 'toggle'])->name('templates.toggle');
        Route::post('templates/{template}/restore', [EmailTemplateController::class, 'restore'])->name('templates.restore');
    });

    Route::get('emails', [EmailLogController::class, 'index'])->name('emails.index');
    Route::get('emails/{message}', [EmailLogController::class, 'show'])->whereNumber('message')->name('emails.show');
    Route::post('emails/{message}/resend', [EmailLogController::class, 'resend'])->whereNumber('message')->middleware('throttle:30,1')->name('emails.resend');
    Route::get('clients/{client}/email', [ClientEmailController::class, 'create'])->name('clients.email.create');
    Route::post('clients/{client}/email', [ClientEmailController::class, 'store'])->middleware('throttle:30,1')->name('clients.email.store');

    Route::get('staff-alerts', [StaffSubscriptionController::class, 'edit'])->name('subscriptions.edit');
    Route::put('staff-alerts', [StaffSubscriptionController::class, 'update'])->name('subscriptions.update');

    // Webhooks can send any client data to any URL: sensitive permission, mutations need a recent password.
    Route::get('webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
    Route::get('webhooks/create', [WebhookEndpointController::class, 'create'])->name('webhooks.create');
    Route::get('webhooks/{endpoint}', [WebhookEndpointController::class, 'show'])->name('webhooks.show');
    Route::get('webhooks/{endpoint}/edit', [WebhookEndpointController::class, 'edit'])->name('webhooks.edit');
    Route::middleware('password.recent')->group(function (): void {
        Route::post('webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
        Route::put('webhooks/{endpoint}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
        Route::delete('webhooks/{endpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
        Route::post('webhooks/{endpoint}/rotate', [WebhookEndpointController::class, 'rotate'])->name('webhooks.rotate');
        Route::post('webhooks/{endpoint}/ping', [WebhookEndpointController::class, 'ping'])->middleware('throttle:10,1')->name('webhooks.ping');
        Route::post('webhooks/{endpoint}/deliveries/{delivery}/resend', [WebhookEndpointController::class, 'resend'])->middleware('throttle:30,1')->name('webhooks.deliveries.resend');
    });
}));

Route::clientArea(fn () => Route::prefix('email')->name('notifications.')->group(function (): void {
    Route::get('preferences', [EmailPreferenceController::class, 'edit'])->name('preferences.edit');
    Route::put('preferences', [EmailPreferenceController::class, 'update'])->name('preferences.update');
    Route::get('history', [EmailHistoryController::class, 'index'])->name('emails.index');
    Route::get('history/{uuid}', [EmailHistoryController::class, 'show'])->whereUuid('uuid')->name('emails.show');
}));
