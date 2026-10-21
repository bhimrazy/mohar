<?php

namespace App\Modules\Kyc\Http\Middleware;

use App\Modules\Kyc\Services\KycVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client verification routes answer 404 while identity verification is off, before any validation runs (spec §2).
 */
class EnsureKycEnabled
{
    public function __construct(private KycVerification $kyc) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->kyc->enabled(), 404);

        return $next($request);
    }
}
