<?php

namespace Tests\Fixtures\Migration\Import;

use Illuminate\Support\Facades\Http;

/**
 * Probe for the record-importer Arch rule (architecture review of #527): an importer that calls a provider over HTTP.
 */
final class HttpImporter
{
    public function upsert(): void
    {
        Http::post('https://registrar.example/renew');
    }
}
