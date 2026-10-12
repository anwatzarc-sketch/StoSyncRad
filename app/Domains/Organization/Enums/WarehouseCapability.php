<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Operational capability flags per warehouse (Chapter 06 §6.2–6.3). */
enum WarehouseCapability: string implements SystemEnum
{
    use HasSystemEnum;

    case RECEIVE_FROM_SUPPLIER = 'receive_from_supplier';
    case QUALITY_CONTROL = 'quality_control';
    case ALLOCATE = 'allocate';
    case DISPATCH_TRANSFERS = 'dispatch_transfers';
    case RECEIVE_TRANSFERS = 'receive_transfers';
    case CROSS_DOCK = 'cross_dock';
    case ISSUE_INTERNAL = 'issue_internal';
    case SELL_RETAIL = 'sell_retail';
    case FULFILL_ECOMMERCE = 'fulfill_ecommerce';
    case PACK_AND_SHIP = 'pack_and_ship';
    case CUSTOMER_RETURNS = 'customer_returns';
    case ISSUE_TO_PRODUCTION = 'issue_to_production';
    case RECEIVE_FROM_PRODUCTION = 'receive_from_production';
    case STOCKTAKE = 'stocktake';
    case ADJUST = 'adjust';
    case RESERVE = 'reserve';
}
