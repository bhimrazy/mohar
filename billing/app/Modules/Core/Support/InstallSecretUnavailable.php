<?php

namespace App\Modules\Core\Support;

use RuntimeException;

/**
 * An install secret's initial value cannot be decided safely from the configured keys (InstallSecrets::define()).
 * Nothing is stored, so the operator can fix the keys and try again.
 */
class InstallSecretUnavailable extends RuntimeException {}
