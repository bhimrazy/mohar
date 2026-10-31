<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Actions\ProcessInboundEmail;
use App\Modules\Support\Drivers\Inbound\GenericDriver;
use App\Modules\Support\Drivers\Inbound\InboundDriver;
use App\Modules\Support\Enums\InboundEmailStatus;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Settings\SupportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/inbound/email/{provider} — email piping webhook (spec §6), HMAC-signed.
 */
class InboundEmailWebhookController extends Controller
{
    /** @var array<string, class-string<InboundDriver>> */
    public const DRIVERS = ['generic' => GenericDriver::class];

    public function __invoke(Request $request, string $provider, ProcessInboundEmail $pipeline, SupportSettings $settings, AttachmentStore $attachments): JsonResponse
    {
        abort_unless(isset(self::DRIVERS[$provider]), 404);

        // Base64 attachments (4/3 overhead) at the configured limits plus 2 MB for text and headers.
        $maxBody = (int) ceil($attachments->maxFiles() * $attachments->maxBytes() * 4 / 3) + 2 * 1024 * 1024;

        if (strlen($request->getContent()) > $maxBody) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

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

        $accepted = in_array($record->status, [InboundEmailStatus::TicketCreated, InboundEmailStatus::ReplyAdded], true);

        return response()->json([
            'id' => $record->id,
            'status' => $record->status->value,
            'duplicate' => ! $record->wasRecentlyCreated,
            'ticket' => $record->ticket()->value('number'),
        ], ! $record->wasRecentlyCreated ? 200 : ($accepted ? 201 : 202));
    }
}
