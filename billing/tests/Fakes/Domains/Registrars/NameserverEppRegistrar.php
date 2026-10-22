<?php

namespace Tests\Fakes\Domains\Registrars;

use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
use Tests\Fakes\Domains\FakeRegistrarDriver;

/**
 * Fake registrar: core plus nameservers and EPP code.
 */
final class NameserverEppRegistrar extends FakeRegistrarDriver implements ManagesNameservers, ProvidesEppCode {}
