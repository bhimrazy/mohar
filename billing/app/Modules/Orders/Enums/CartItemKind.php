<?php

namespace App\Modules\Orders\Enums;

enum CartItemKind: string
{
    case Product = 'product';
    case DomainRegister = 'domain_register';
    case DomainTransfer = 'domain_transfer';
}
