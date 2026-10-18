<?php

namespace App\Modules\Support\Enums;

enum MessageType: string
{
    case Reply = 'reply';
    /** Staff-only internal note: never shown or emailed to clients, never changes status. */
    case Note = 'note';
}
