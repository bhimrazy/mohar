<?php

namespace App\Modules\Storefront\Exceptions;

use RuntimeException;

/**
 * The draft was saved again after the publisher opened the confirmation; they must review the new changes first.
 */
class DraftChanged extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The draft changed since you opened it. Review the changes again.');
    }
}
