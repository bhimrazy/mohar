<?php

namespace App\Modules\Core\Install;

use RuntimeException;

/**
 * The installer refused to create a full administrator. The message is shown to the operator as is.
 */
final class AdministratorRefused extends RuntimeException {}
