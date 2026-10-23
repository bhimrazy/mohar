<?php

namespace App\Modules\Billing\Exceptions;

/**
 * The invoice (or the plan) changed between the preview and the confirm of a note.
 */
class StaleNoteException extends BillingException
{
    public static function make(): self
    {
        return new self(__('The invoice changed; review the note again.'));
    }
}
