<?php

use App\Modules\Storefront\Http\Controllers\Public\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'show'])->name('home');
Route::get('domains/pricing', [HomeController::class, 'domainPricing'])->name('storefront.domain-pricing');

// Unknown public URLs render the public 404 page (HTTP 404, noindex) with sessions and shared props available.
Route::fallback([HomeController::class, 'notFound'])->name('storefront.not-found');

// GET /sitemap.xml is registered in StorefrontServiceProvider, outside the "web" middleware group.
