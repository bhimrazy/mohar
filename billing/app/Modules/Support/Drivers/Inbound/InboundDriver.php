<?php

namespace App\Modules\Support\Drivers\Inbound;

use Illuminate\Http\Request;

/**
 * Normalises one email provider's inbound webhook (spec §6).
 *
 * Drivers must not derive InboundMessage::$authenticated from mail headers; only a provider that
 * computes SPF/DKIM/DMARC itself and returns it as structured fields of its own signed payload may set it,
 * and the driver must document which fields it reads.
 *
 * Replay: verify() must authenticate a timestamp (or the provider's own one-time token) and refuse a request older
 * than a few minutes, as provider signatures usually allow. GenericDriver signs the body only and relies on the
 * message_id deduplication instead; a new driver may not (security review 2026-10-19, L5).
 */
interface InboundDriver
{
    /**
     * Whether the request is authentic for the shared secret.
     */
    public function verify(Request $request, string $secret): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): InboundMessage;
}
