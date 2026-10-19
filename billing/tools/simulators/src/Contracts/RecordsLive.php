<?php

namespace Mohar\Simulators\Contracts;

use Mohar\Simulators\Kernel\Recorder;

/**
 * A simulator that can replay its operation script against the provider's real sandbox (`simulate:record`), using only
 * credentials it reads from the environment, so the shapes it serves can be audited against `source: live` recordings.
 */
interface RecordsLive
{
    /**
     * Run the operation script and hand each response to the recorder. Throws when the credentials are missing.
     */
    public function recordLive(Recorder $recorder): void;
}
