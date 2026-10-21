<?php

namespace App\Modules\Kyc\Http\Middleware;

use App\Modules\Core\Support\ClientContext;
use App\Modules\Kyc\Enums\KycStatus;
use App\Modules\Kyc\Services\KycVerification;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the client-area verification banner (spec §4). Lazy: resolved only when an Inertia page renders, after the
 * client.area middleware picked the account; null outside the client area, when KYC is off, or once verified.
 */
class ShareKycBanner
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('kyc', function () use ($request): ?array {
            $context = app(ClientContext::class);
            $kyc = app(KycVerification::class);

            if (! str_starts_with((string) $request->route()?->getName(), 'client.') || ! $context->isResolved() || ! $kyc->enabled() || ! $context->can('profile')) {
                return null;
            }

            $status = $kyc->status($context->client());

            return $status === KycStatus::Verified ? null : ['status' => $status->value, 'href' => route('client.kyc.show')];
        });

        return $next($request);
    }
}
