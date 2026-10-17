<?php

use App\Modules\Orders\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Modules\Orders\Http\Controllers\Admin\PromotionController;
use App\Modules\Orders\Http\Controllers\Client\OrderController as ClientOrderController;
use App\Modules\Orders\Http\Controllers\Public\CartController;
use App\Modules\Orders\Http\Controllers\Public\CheckoutController;
use App\Modules\Orders\Http\Controllers\Public\StoreController;
use Illuminate\Support\Facades\Route;

Route::prefix('store')->name('store.')->group(function (): void {
    Route::get('/', [StoreController::class, 'index'])->name('index');
    Route::get('products/{product}', [StoreController::class, 'show'])->whereNumber('product')->name('products.show');
    Route::post('products/{product}/quote', [StoreController::class, 'quote'])->whereNumber('product')->middleware('throttle:120,1')->name('products.quote');
    Route::get('domains', [StoreController::class, 'domains'])->name('domains');
    Route::put('currency', [StoreController::class, 'currency'])->name('currency');

    Route::get('cart', [CartController::class, 'show'])->name('cart');
    Route::post('cart/products/{product}', [CartController::class, 'storeProduct'])->whereNumber('product')->name('cart.products.store');
    Route::put('cart/items/{item}', [CartController::class, 'updateProduct'])->name('cart.items.update');
    Route::delete('cart/items/{item}', [CartController::class, 'destroy'])->name('cart.items.destroy');
    Route::post('cart/domains', [CartController::class, 'storeDomain'])->name('cart.domains.store');
    Route::post('cart/promotion', [CartController::class, 'applyPromotion'])->middleware('throttle:20,1')->name('cart.promotion.store');
    Route::delete('cart/promotion', [CartController::class, 'removePromotion'])->name('cart.promotion.destroy');

    Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout');
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
    Route::get('orders/{order:number}/complete', [CheckoutController::class, 'complete'])->name('checkout.complete');
});

Route::adminArea(function (): void {
    Route::prefix('orders')->name('orders.')->controller(AdminOrderController::class)->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::post('bulk', 'bulk')->name('bulk');
        Route::get('{order}', 'show')->name('show');
        Route::post('{order}/accept', 'accept')->name('accept');
        Route::post('{order}/cancel', 'cancel')->name('cancel');
        Route::post('{order}/fraud', 'markFraud')->name('fraud');
        Route::post('{order}/pending', 'setPending')->name('pending');
        Route::post('{order}/fraud-check', 'runFraudCheck')->name('fraud-check');
        Route::delete('{order}', 'destroy')->name('destroy');
    });

    Route::resource('promotions', PromotionController::class)->except('show');
    Route::post('promotions/{promotion}/duplicate', [PromotionController::class, 'duplicate'])->name('promotions.duplicate');
    Route::post('promotions/{promotion}/expire', [PromotionController::class, 'expire'])->name('promotions.expire');
});

Route::clientArea(function (): void {
    Route::get('orders', [ClientOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [ClientOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
});
