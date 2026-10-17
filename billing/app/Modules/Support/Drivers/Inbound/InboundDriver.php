<?php

namespace App\Modules\Support\Drivers\Inbound;

use Illuminate\Http\Request;

/**
 * Normalises one email provider's inbound webhook (spec §6).
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
