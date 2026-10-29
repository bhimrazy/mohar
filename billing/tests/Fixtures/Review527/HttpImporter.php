<?php
namespace Tests\Fixtures\Review527;
use Illuminate\Support\Facades\Http;
final class HttpImporter { public function upsert(): void { Http::post('https://registrar.example/renew'); } }
