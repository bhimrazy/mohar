<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 404 unless `config/features.php` switches the flag on (`feature:<module>.<flag>`). For unfinished work only; no flag exists at present. */
class RequireFeature
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        abort_unless((bool) config("features.{$flag}"), 404);

        return $next($request);
    }
}
