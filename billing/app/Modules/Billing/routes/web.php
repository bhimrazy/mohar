<?php

use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Http\Controllers\Admin\CreditController as AdminCreditController;
use App\Modules\Billing\Http\Controllers\Admin\GatewayController;
use App\Modules\Billing\Http\Controllers\Admin\GatewayLogController;
use App\Modules\Billing\Http\Controllers\Admin\InvoiceActionController;
use App\Modules\Billing\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Modules\Billing\Http\Controllers\Admin\PaymentAttemptController;
use App\Modules\Billing\Http\Controllers\Admin\QuoteController as AdminQuoteController;
use App\Modules\Billing\Http\Controllers\Admin\TaxRuleController;
use App\Modules\Billing\Http\Controllers\Admin\TransactionController;
use App\Modules\Billing\Http\Controllers\Client\CreditController;
use App\Modules\Billing\Http\Controllers\Client\InvoiceController;
use App\Modules\Billing\Http\Controllers\Client\PaymentBatchController;
use App\Modules\Billing\Http\Controllers\Client\PayMethodController;
use App\Modules\Billing\Http\Controllers\Client\QuoteController;
use App\Modules\Billing\Http\Controllers\Public\GatewayReturnController;
use App\Modules\Billing\Http\Controllers\Public\SandboxHostedController;
use App\Modules\Billing\Http\Controllers\Public\SandboxVaultController;
use App\Modules\Billing\Http\Controllers\Public\WebhookController;
use App\Modules\Billing\Http\Middleware\ConfirmPasswordForAction;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::adminArea(fn () => Route::prefix('billing')->name('billing.')->group(function (): void {
    Route::post('invoices/bulk', [InvoiceActionController::class, 'bulk'])->middleware(ConfirmPasswordForAction::class)->name('invoices.bulk');
    Route::resource('invoices', AdminInvoiceController::class);
    Route::controller(InvoiceActionController::class)->prefix('invoices/{invoice}')->name('invoices.')->group(function (): void {
        Route::patch('details', [AdminInvoiceController::class, 'updateDetails'])->name('details');
        Route::post('publish', 'publish')->name('publish');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('reopen', 'reopen')->name('reopen');
        Route::post('remind', 'remind')->name('remind');
        Route::post('payments', 'recordPayment')->middleware(ConfirmPasswordForAction::class)->name('payments.store');
        Route::post('mark-paid', 'markPaid')->middleware(ConfirmPasswordForAction::class)->name('mark-paid');
        Route::post('capture', 'capture')->name('capture');
        Route::post('cancel-lines', 'cancelLines')->name('cancel-lines');
        Route::post('credit', 'applyCredit')->middleware(ConfirmPasswordForAction::class)->name('credit.apply');
        Route::delete('credit', 'removeCredit')->middleware(ConfirmPasswordForAction::class)->name('credit.remove');
        Route::get('pdf', 'pdf')->name('pdf');
    });

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->middleware(ConfirmPasswordForAction::class)->name('transactions.store');
    Route::post('transactions/{transaction}/refund', [TransactionController::class, 'refund'])->middleware(ConfirmPasswordForAction::class)->name('transactions.refund');

    Route::resource('quotes', AdminQuoteController::class);
    Route::post('quotes/{quote}/deliver', [AdminQuoteController::class, 'deliver'])->name('quotes.deliver');
    Route::post('quotes/{quote}/convert', [AdminQuoteController::class, 'convert'])->name('quotes.convert');
    Route::get('quotes/{quote}/pdf', [AdminQuoteController::class, 'pdf'])->name('quotes.pdf');

    Route::get('credit', [AdminCreditController::class, 'index'])->name('credit.index');
    Route::put('clients/{client}/billing-preferences', [AdminCreditController::class, 'preferences'])->name('clients.preferences');
    Route::post('credit', [AdminCreditController::class, 'store'])->middleware(ConfirmPasswordForAction::class)->name('credit.store');

    Route::get('gateways', [GatewayController::class, 'index'])->name('gateways.index');
    Route::get('gateways/{gateway}/edit', [GatewayController::class, 'edit'])->name('gateways.edit')->where('gateway', '[a-z_]+');
    Route::put('gateways/{gateway}', [GatewayController::class, 'update'])->middleware(ConfirmPasswordForAction::class)->name('gateways.update')->where('gateway', '[a-z_]+');

    Route::get('tax-rules', [TaxRuleController::class, 'index'])->name('tax-rules.index');
    Route::resource('tax-rules', TaxRuleController::class)->only(['store', 'update', 'destroy'])->parameters(['tax-rules' => 'taxRule'])->middleware(ConfirmPasswordForAction::class);
    Route::get('gateway-log', [GatewayLogController::class, 'index'])->name('gateway-log.index');
    Route::get('payment-attempts', [PaymentAttemptController::class, 'index'])->name('payment-attempts.index');
    Route::post('payment-attempts/{attempt}/check', [PaymentAttemptController::class, 'check'])->name('payment-attempts.check');
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

// Hosted gateway returns (GET or POST from the provider). Hints only: always settled by an authoritative lookup.
Route::match(['get', 'post'], 'billing/gateways/{gateway}/return', GatewayReturnController::class)
    ->where('gateway', '[a-z_]{1,50}')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:60,1')
    ->name('billing.gateways.return');

// The sandbox gateway's simulated hosted page and card vault. Never registered in production (see SandboxCardDriver::enabled()).
if (SandboxCardDriver::enabled()) {
    Route::get('billing/sandbox/checkout/{reference}', [SandboxHostedController::class, 'show'])->where('reference', '[a-z0-9-]{3,40}')->name('billing.sandbox.checkout');
    Route::post('billing/sandbox/checkout/{reference}', [SandboxHostedController::class, 'pay'])->where('reference', '[a-z0-9-]{3,40}')->middleware('throttle:30,1')->name('billing.sandbox.checkout.pay');

    // Sandbox "hosted fields": the browser posts card data here (JSON, CSRF-protected) and receives a token.
    Route::post('billing/sandbox/tokens', SandboxVaultController::class)
        ->middleware(['auth', 'throttle:30,1'])
        ->name('billing.sandbox.tokens');
}
