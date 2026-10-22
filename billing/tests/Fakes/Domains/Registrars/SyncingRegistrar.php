<?php

namespace Tests\Fakes\Domains\Registrars;

use App\Contracts\Domains\Registrar\SyncsDomains;
use Tests\Fakes\Domains\FakeRegistrarDriver;

/**
 * Fake registrar: core plus sync.
 */
final class SyncingRegistrar extends FakeRegistrarDriver implements SyncsDomains {}
