<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Ledger movement type (Chapter 07 §7.4). */
enum MovementType: string implements SystemEnum
{
    use HasSystemEnum;

    case RECEIPT = 'receipt';
    case SUPPLIER_RETURN = 'supplier_return';
    case TRANSFER_DISPATCH = 'transfer_dispatch';
    case TRANSFER_RECEIPT = 'transfer_receipt';
    case ISSUE = 'issue';
    case SALE_DELIVERY = 'sale_delivery';
    case CUSTOMER_RETURN = 'customer_return';
    case INTERNAL_RETURN = 'internal_return';
    case ADJUSTMENT_IN = 'adjustment_in';
    case ADJUSTMENT_OUT = 'adjustment_out';
    case CONDITION_CHANGE = 'condition_change';
    case RELOCATION = 'relocation';
    case OPENING_BALANCE = 'opening_balance';
    case SCRAP = 'scrap';
    case PRODUCTION_ISSUE = 'production_issue';
    case PRODUCTION_RECEIPT = 'production_receipt';
    case REVERSAL = 'reversal';
    case CROSS_DOCK = 'cross_dock';
    case TRANSFER_LOSS = 'transfer_loss';
    case TRANSFER_RETURN = 'transfer_return';
}
