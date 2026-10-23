<?php

namespace App\Modules\Core\Support;

/**
 * What a committed sign-in email change managed to mail.
 */
final readonly class LoginEmailChanged
{
    public function __construct(public bool $verificationSent, public bool $oldAddressTold) {}
}
