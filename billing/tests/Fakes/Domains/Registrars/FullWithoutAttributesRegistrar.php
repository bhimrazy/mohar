<?php

namespace Tests\Fakes\Domains\Registrars;

use App\Contracts\Domains\Registrar\ChecksAvailability;
use App\Contracts\Domains\Registrar\ManagesContacts;
use App\Contracts\Domains\Registrar\ManagesIdProtection;
use App\Contracts\Domains\Registrar\ManagesLock;
use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Contracts\Domains\Registrar\ProvidesEppCode;
use App\Contracts\Domains\Registrar\RestoresDomains;
use App\Contracts\Domains\Registrar\SuggestsNames;
use App\Contracts\Domains\Registrar\SyncsDomains;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Contracts\Domains\Registrar\TransfersDomains;
use Tests\Fakes\Domains\FakeRegistrarDriver;

/**
 * Every capability except `tld_attributes`: a driver written before that capability existed.
 */
final class FullWithoutAttributesRegistrar extends FakeRegistrarDriver implements ChecksAvailability, ManagesContacts, ManagesIdProtection, ManagesLock, ManagesNameservers, ProvidesEppCode, RestoresDomains, SuggestsNames, SyncsDomains, TestsRegistrarConnection, TransfersDomains {}
