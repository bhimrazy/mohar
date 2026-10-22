<?php

namespace Tests\Fakes\Domains\Registrars;

use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\SyncsDomains;
use Tests\Fakes\Domains\FakeRegistrarDriver;

/**
 * Fake registrar: core plus nameservers and sync.
 */
final class NameserverSyncingRegistrar extends FakeRegistrarDriver implements ManagesNameservers, SyncsDomains {}
