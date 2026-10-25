<?php

namespace App\Support\Integrations;

use RuntimeException;

/**
 * An uploaded certificate file that cannot be stored. The message is written for staff and is safe to show: it never
 * carries the passphrase, the key or OpenSSL's own text.
 */
final class CertificateRefused extends RuntimeException {}
