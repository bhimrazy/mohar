<?php

namespace Tests\Fakes\Domains\Registrars;

use App\Contracts\Domains\Registrar\ManagesNameservers;
use Tests\Fakes\Domains\FakeRegistrarDriver;

/**
 * Fake registrar: core plus nameservers.
 */
final class NameserverRegistrar extends FakeRegistrarDriver implements ManagesNameservers {}
