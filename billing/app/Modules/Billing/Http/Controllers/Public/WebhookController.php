<?php

namespace App\Modules\Billing\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gateway webhooks/callbacks — the only way a gateway can mark something paid.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayCallbackHandler $handler): JsonResponse
    {
        $outcome = $handler->handle($gateway, $request);

        return response()->json(['result' => $outcome['result']], $outcome['status']);
    }
}
