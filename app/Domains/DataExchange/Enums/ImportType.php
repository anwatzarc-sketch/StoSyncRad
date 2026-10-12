<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Supported import types. */
enum ImportType: string implements SystemEnum
{
    use HasSystemEnum;

    case ITEMS = 'items';
    case VARIANTS = 'variants';
    case CATEGORIES = 'categories';
    case UOM = 'uom';
    case BARCODES = 'barcodes';
    case PACKAGINGS = 'packagings';
    case VENDOR_PRICELISTS = 'vendor_pricelists';
    case REORDER_RULES = 'reorder_rules';
    case BRANCHES = 'branches';
    case WAREHOUSES = 'warehouses';
    case STORAGE_LOCATIONS = 'storage_locations';
    case PARTNERS = 'partners';
    case LOTS = 'lots';
    case OPENING_BALANCES = 'opening_balances';
    case ROLES = 'roles';
}
