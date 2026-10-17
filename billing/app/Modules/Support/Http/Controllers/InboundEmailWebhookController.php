<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Actions\ProcessInboundEmail;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Drivers\Inbound\InboundDriver;
use App\Modules\Support\Services\SupportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/inbound/email/{provider} — email piping webhook (spec §6), HMAC-signed.
 */
class InboundEmailWebhookController extends Controller
{
    /** @var array<string, class-string<InboundDriver>> */
    public const DRIVERS = ['generic' => GenericDriver::class];

    public function __invoke(Request $request, string $provider, ProcessInboundEmail $pipeline, SupportSettings $settings): JsonResponse
    {
        abort_unless(isset(self::DRIVERS[$provider]), 404);

        /** @var InboundDriver $driver */
        $driver = app(self::DRIVERS[$provider]);

        if (! $driver->verify($request, $settings->string('inbound_secret'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Expected a JSON object.'], 422);
        }

        $record = $pipeline->handle($driver->parse($payload), $payload);

        return response()->json([
            'id' => $record->id,
            'status' => $record->status->value,
            'duplicate' => ! $record->wasRecentlyCreated,
            'ticket' => $record->ticket()->value('number'),
        ], $record->wasRecentlyCreated ? 201 : 200);
    }
}
