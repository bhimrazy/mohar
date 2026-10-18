<?php

use App\Modules\Support\Http\Controllers\InboundEmailWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Loaded under /api/v1 with the "api" middleware group. Authenticated by HMAC signature
| (support.inbound_secret), not by session/CSRF.
*/
Route::post('inbound/email/{provider}', InboundEmailWebhookController::class)
    ->middleware('throttle:support-inbound')
    ->where('provider', '[a-z0-9_-]+')
    ->name('support.inbound.email');
