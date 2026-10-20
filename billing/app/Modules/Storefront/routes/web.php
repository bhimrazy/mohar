<?php

use App\Modules\Storefront\Http\Controllers\Admin\WebsiteController;
use App\Modules\Storefront\Http\Controllers\Public\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'show'])->name('home');
Route::get('domains/pricing', [HomeController::class, 'domainPricing'])->name('storefront.domain-pricing');
Route::adminArea(function (): void {
    Route::get('website', [WebsiteController::class, 'edit'])->name('storefront.website.edit');
    Route::put('website', [WebsiteController::class, 'update'])->name('storefront.website.update');
    // Publishing changes what every visitor sees: a sensitive permission with step-up (security review).
    Route::post('website/publish', [WebsiteController::class, 'publish'])->middleware('password.recent')->name('storefront.website.publish');
    // The draft as visitors would see it: admin-area checks, a signed 60-minute link, the public theme and layout.
    Route::get('website/preview', [HomeController::class, 'preview'])->middleware('signed')->defaults('theme_area', 'public')->name('storefront.website.preview');
    Route::delete('website/draft', [WebsiteController::class, 'discard'])->name('storefront.website.discard');
});

// Unknown public URLs render the public 404 page (HTTP 404, noindex) with sessions and shared props available.
Route::fallback([HomeController::class, 'notFound'])->name('storefront.not-found');

// GET /sitemap.xml is registered in StorefrontServiceProvider, outside the "web" middleware group.
