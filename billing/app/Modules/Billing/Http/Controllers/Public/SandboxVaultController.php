<?php

namespace App\Modules\Billing\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Http\Requests\Client\SandboxTokenRequest;
use Illuminate\Http\JsonResponse;

/**
 * Stand-in for the sandbox gateway's hosted card fields: turns card details into a token (JSON).
 * Nothing is stored or logged here; the browser forwards only the token to the application.
 */
class SandboxVaultController extends Controller
{
    public function __invoke(SandboxTokenRequest $request, GatewayManager $gateways): JsonResponse
    {
        $driver = $gateways->driver('sandbox_card');
        abort_unless($driver instanceof SandboxCardDriver && $gateways->isActive('sandbox_card'), 404);
        $token = $driver->tokenize($request->string('card_number')->toString(), $request->integer('exp_month'), $request->integer('exp_year'));

        return response()->json([
            'token' => $token->token,
            'brand' => $token->brand,
            'last4' => $token->last4,
            'exp_month' => $token->expiryMonth,
            'exp_year' => $token->expiryYear,
        ]);
    }
}
