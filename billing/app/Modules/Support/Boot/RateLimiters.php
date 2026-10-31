<?php

namespace App\Modules\Support\Boot;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class RateLimiters
{
    public function __invoke(Container $app): void
    {
        RateLimiter::for('support-guest', fn (Request $request): Limit => Limit::perMinute(6)->by('support-guest|'.$request->ip()));
        RateLimiter::for('support-public', fn (Request $request): Limit => Limit::perMinute(60)->by('support-public|'.$request->ip()));
        RateLimiter::for('support-inbound', fn (Request $request): Limit => Limit::perMinute(120)->by('support-inbound|'.$request->ip()));
    }
}
