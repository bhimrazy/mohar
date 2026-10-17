<?php

use App\Modules\Billing\Http\Controllers\Admin\CreditController as AdminCreditController;
use App\Modules\Billing\Http\Controllers\Admin\GatewayController;
use App\Modules\Billing\Http\Controllers\Admin\GatewayLogController;
use App\Modules\Billing\Http\Controllers\Admin\InvoiceActionController;
use App\Modules\Billing\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Modules\Billing\Http\Controllers\Admin\QuoteController as AdminQuoteController;
use App\Modules\Billing\Http\Controllers\Admin\TaxRuleController;
use App\Modules\Billing\Http\Controllers\Admin\TransactionController;
use App\Modules\Billing\Http\Controllers\Client\CreditController;
use App\Modules\Billing\Http\Controllers\Client\InvoiceController;
use App\Modules\Billing\Http\Controllers\Client\PaymentBatchController;
use App\Modules\Billing\Http\Controllers\Client\PayMethodController;
use App\Modules\Billing\Http\Controllers\Client\QuoteController;
use App\Modules\Billing\Http\Controllers\Public\SandboxVaultController;
use App\Modules\Billing\Http\Controllers\Public\WebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::prefix('billing')->name('billing.')->group(function (): void {
    Route::post('invoices/bulk', [InvoiceActionController::class, 'bulk'])->name('invoices.bulk');
    Route::resource('invoices', AdminInvoiceController::class);
    Route::controller(InvoiceActionController::class)->prefix('invoices/{invoice}')->name('invoices.')->group(function (): void {
        Route::patch('details', [AdminInvoiceController::class, 'updateDetails'])->name('details');
        Route::post('publish', 'publish')->name('publish');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('reopen', 'reopen')->name('reopen');
        Route::post('remind', 'remind')->name('remind');
        Route::post('payments', 'recordPayment')->name('payments.store');
        Route::post('mark-paid', 'markPaid')->name('mark-paid');
        Route::post('capture', 'capture')->name('capture');
        Route::post('cancel-lines', 'cancelLines')->name('cancel-lines');
        Route::post('credit', 'applyCredit')->name('credit.apply');
        Route::delete('credit', 'removeCredit')->name('credit.remove');
        Route::get('pdf', 'pdf')->name('pdf');
    });

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::post('transactions/{transaction}/refund', [TransactionController::class, 'refund'])->name('transactions.refund');

    Route::resource('quotes', AdminQuoteController::class);
    Route::post('quotes/{quote}/deliver', [AdminQuoteController::class, 'deliver'])->name('quotes.deliver');
    Route::post('quotes/{quote}/convert', [AdminQuoteController::class, 'convert'])->name('quotes.convert');
    Route::get('quotes/{quote}/pdf', [AdminQuoteController::class, 'pdf'])->name('quotes.pdf');

    Route::get('credit', [AdminCreditController::class, 'index'])->name('credit.index');
    Route::post('credit', [AdminCreditController::class, 'store'])->name('credit.store');

    Route::get('gateways', [GatewayController::class, 'index'])->name('gateways.index');
    Route::get('gateways/{gateway}/edit', [GatewayController::class, 'edit'])->name('gateways.edit')->where('gateway', '[a-z_]+');
    Route::put('gateways/{gateway}', [GatewayController::class, 'update'])->name('gateways.update')->where('gateway', '[a-z_]+');

    Route::resource('tax-rules', TaxRuleController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['tax-rules' => 'taxRule']);
    Route::get('gateway-log', [GatewayLogController::class, 'index'])->name('gateway-log.index');
}));

Route::clientArea(function (): void {
    Route::prefix('invoices')->name('invoices.')->controller(InvoiceController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('batches', [PaymentBatchController::class, 'store'])->name('batches.store');
        Route::get('batches/{batch}', [PaymentBatchController::class, 'show'])->whereNumber('batch')->name('batches.show');
        Route::post('batches/{batch}/pay', [PaymentBatchController::class, 'pay'])->whereNumber('batch')->name('batches.pay');
        Route::get('{invoice}', 'show')->whereNumber('invoice')->name('show');
        Route::get('{invoice}/pdf', 'pdf')->whereNumber('invoice')->name('pdf');
        Route::post('{invoice}/pay', 'pay')->whereNumber('invoice')->name('pay');
        Route::post('{invoice}/credit', 'applyCredit')->whereNumber('invoice')->name('credit');
    });

    Route::prefix('quotes')->name('quotes.')->controller(QuoteController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('{quote}', 'show')->whereNumber('quote')->name('show');
        Route::get('{quote}/pdf', 'pdf')->whereNumber('quote')->name('pdf');
        Route::post('{quote}/accept', 'accept')->whereNumber('quote')->name('accept');
        Route::post('{quote}/decline', 'decline')->whereNumber('quote')->name('decline');
    });

    Route::get('credit', [CreditController::class, 'index'])->name('credit.index');
    Route::post('credit/add-funds', [CreditController::class, 'addFunds'])->name('credit.add-funds');

    Route::prefix('payment-methods')->name('pay-methods.')->controller(PayMethodController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('{payMethod}/default', 'makeDefault')->whereNumber('payMethod')->name('default');
        Route::delete('{payMethod}', 'destroy')->whereNumber('payMethod')->name('destroy');
    });
});

// Gateway callbacks: authenticated by signature, not session/CSRF.
Route::post('billing/webhooks/{gateway}', WebhookController::class)
    ->where('gateway', '[a-z_]{1,50}')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:120,1')
    ->name('billing.webhooks');

// Sandbox gateway "hosted fields": the browser posts card data here (JSON) and receives a token.
Route::post('billing/sandbox/tokens', SandboxVaultController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware(['auth', 'throttle:30,1'])
    ->name('billing.sandbox.tokens');
