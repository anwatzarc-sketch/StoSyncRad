<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How the customer paid. */
enum PaymentMethod: string implements SystemEnum
{
    use HasSystemEnum;

    case BANK_TRANSFER = 'bank_transfer';
    case MOBILE_BANKING = 'mobile_banking';
    case CASH_DEPOSIT = 'cash_deposit';
    case CASH = 'cash';
    case CHEQUE = 'cheque';
    case OTHER = 'other';
}
