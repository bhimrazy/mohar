<?php

use App\Modules\Domains\Http\Controllers\Admin\DomainActionController;
use App\Modules\Domains\Http\Controllers\Admin\DomainController as AdminDomainController;
use App\Modules\Domains\Http\Controllers\Admin\NpRegistrationController;
use App\Modules\Domains\Http\Controllers\Admin\RegistrarController;
use App\Modules\Domains\Http\Controllers\Admin\TldController;
use App\Modules\Domains\Http\Controllers\Client\DomainController as ClientDomainController;
use App\Modules\Domains\Http\Controllers\Client\NpDocumentController;
use App\Modules\Domains\Http\Controllers\Public\DomainSearchController;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::prefix('domains')->name('domains.')->group(function (): void {
    Route::get('tlds', [TldController::class, 'index'])->name('tlds.index');
    Route::get('tlds/create', [TldController::class, 'create'])->name('tlds.create');
    Route::post('tlds', [TldController::class, 'store'])->name('tlds.store');
    Route::get('tlds/{tld}/edit', [TldController::class, 'edit'])->name('tlds.edit');
    Route::put('tlds/{tld}', [TldController::class, 'update'])->name('tlds.update');
    Route::put('tlds/{tld}/prices', [TldController::class, 'prices'])->name('tlds.prices');

    Route::get('registrars', [RegistrarController::class, 'index'])->name('registrars.index');
    // Registrar credentials are sensitive: changing or using them needs a recent password confirmation.
    Route::middleware('password.recent')->group(function (): void {
        Route::put('registrars/{registrar}', [RegistrarController::class, 'update'])->name('registrars.update');
        Route::post('registrars/{registrar}/test', [RegistrarController::class, 'test'])->middleware('throttle:6,1')->name('registrars.test');
    });

    Route::get('/', [AdminDomainController::class, 'index'])->name('index');
    Route::get('{domain}', [AdminDomainController::class, 'show'])->whereNumber('domain')->name('show');
    Route::put('{domain}', [AdminDomainController::class, 'update'])->whereNumber('domain')->name('update');
    Route::delete('{domain}', [AdminDomainController::class, 'destroy'])->whereNumber('domain')->name('destroy');
    Route::put('{domain}/status', [DomainActionController::class, 'status'])->whereNumber('domain')->name('status');
    Route::put('{domain}/nameservers', [DomainActionController::class, 'nameservers'])->whereNumber('domain')->name('nameservers');
    Route::put('{domain}/lock', [DomainActionController::class, 'lock'])->whereNumber('domain')->name('lock');
    Route::put('{domain}/contacts', [DomainActionController::class, 'contacts'])->whereNumber('domain')->name('contacts');
    Route::post('{domain}/epp', [DomainActionController::class, 'epp'])->whereNumber('domain')->name('epp');
    Route::post('{domain}/move', [DomainActionController::class, 'move'])->whereNumber('domain')->name('move');
    Route::post('{domain}/renewal-invoice', [DomainActionController::class, 'renewalInvoice'])->whereNumber('domain')->name('renewal-invoice');
    Route::post('{domain}/commands/{command}', [DomainActionController::class, 'command'])
        ->whereNumber('domain')->whereIn('command', ['register', 'transfer', 'renew', 'sync'])->name('command');
    Route::post('{domain}/np/{action}', [NpRegistrationController::class, 'step'])
        ->whereNumber('domain')->whereIn('action', ['review', 'submitted', 'decision', 'confirm'])->name('np.step');
    Route::get('{domain}/np/documents/{document}/open', [NpRegistrationController::class, 'open'])
        ->whereNumber('domain')->whereNumber('document')->name('np.document.open');
    Route::get('{domain}/np/documents/{document}', [NpRegistrationController::class, 'document'])
        ->whereNumber('domain')->whereNumber('document')->middleware('signed')->name('np.document');
}));

Route::clientArea(fn () => Route::prefix('domains')->name('domains.')->group(function (): void {
    Route::get('/', [ClientDomainController::class, 'index'])->name('index');
    Route::get('{domain}', [ClientDomainController::class, 'show'])->whereNumber('domain')->name('show');
    Route::put('{domain}/nameservers', [ClientDomainController::class, 'nameservers'])->whereNumber('domain')->name('nameservers');
    Route::put('{domain}/auto-renew', [ClientDomainController::class, 'autoRenew'])->whereNumber('domain')->name('auto-renew');
    Route::put('{domain}/lock', [ClientDomainController::class, 'lock'])->whereNumber('domain')->name('lock');
    Route::put('{domain}/contacts', [ClientDomainController::class, 'contacts'])->whereNumber('domain')->name('contacts');
    Route::post('{domain}/epp', [ClientDomainController::class, 'epp'])->whereNumber('domain')->middleware('throttle:10,1')->name('epp');
    Route::put('{domain}/transfer', [ClientDomainController::class, 'resubmitTransfer'])->whereNumber('domain')->name('transfer');
    Route::post('{domain}/renew', [ClientDomainController::class, 'renew'])->whereNumber('domain')->name('renew');
    Route::post('{domain}/np/documents', [NpDocumentController::class, 'store'])->whereNumber('domain')->middleware('throttle:10,1')->name('np.documents.store');
    Route::post('{domain}/np/review', [NpDocumentController::class, 'submit'])->whereNumber('domain')->name('np.review');
}));

Route::prefix('domains')->name('domains.')->middleware('throttle:domain-lookup')->group(function (): void {
    Route::get('search', [DomainSearchController::class, 'page'])->name('search');
    Route::get('lookup', [DomainSearchController::class, 'lookup'])->name('lookup');
});
