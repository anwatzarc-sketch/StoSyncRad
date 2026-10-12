<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Kind of company-owned operating site (Chapter 06 §6.2). */
enum BranchKind: string implements SystemEnum
{
    use HasSystemEnum;

    case HEADQUARTERS = 'headquarters';
    case RETAIL_STORE = 'retail_store';
    case DISTRIBUTION_DEPOT = 'distribution_depot';
    case ECOMMERCE_FULFILLMENT_HUB = 'ecommerce_fulfillment_hub';
    case PRODUCTION_STORE = 'production_store';
    case SERVICE_CENTER = 'service_center';
    case TRANSIT_HUB = 'transit_hub';
    case OTHER = 'other';

    /** Default warehouse capabilities seeded for this branch kind (editable per warehouse). */
    public function defaultCapabilities(): array
    {
        $c = static fn (WarehouseCapability ...$caps) => array_map(static fn (WarehouseCapability $x) => $x->value, $caps);

        return match ($this) {
            self::HEADQUARTERS => $c(WarehouseCapability::RECEIVE_FROM_SUPPLIER, WarehouseCapability::QUALITY_CONTROL, WarehouseCapability::ALLOCATE, WarehouseCapability::DISPATCH_TRANSFERS, WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::ISSUE_INTERNAL, WarehouseCapability::STOCKTAKE, WarehouseCapability::ADJUST, WarehouseCapability::RESERVE),
            self::DISTRIBUTION_DEPOT => $c(WarehouseCapability::RECEIVE_FROM_SUPPLIER, WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::DISPATCH_TRANSFERS, WarehouseCapability::CROSS_DOCK, WarehouseCapability::ISSUE_INTERNAL, WarehouseCapability::STOCKTAKE, WarehouseCapability::ADJUST, WarehouseCapability::RESERVE),
            self::RETAIL_STORE => $c(WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::SELL_RETAIL, WarehouseCapability::CUSTOMER_RETURNS, WarehouseCapability::ISSUE_INTERNAL, WarehouseCapability::DISPATCH_TRANSFERS, WarehouseCapability::STOCKTAKE, WarehouseCapability::ADJUST, WarehouseCapability::RESERVE),
            self::ECOMMERCE_FULFILLMENT_HUB => $c(WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::RECEIVE_FROM_SUPPLIER, WarehouseCapability::FULFILL_ECOMMERCE, WarehouseCapability::PACK_AND_SHIP, WarehouseCapability::CUSTOMER_RETURNS, WarehouseCapability::STOCKTAKE, WarehouseCapability::ADJUST, WarehouseCapability::RESERVE),
            self::PRODUCTION_STORE => $c(WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::ISSUE_TO_PRODUCTION, WarehouseCapability::RECEIVE_FROM_PRODUCTION, WarehouseCapability::STOCKTAKE, WarehouseCapability::ADJUST, WarehouseCapability::RESERVE),
            self::SERVICE_CENTER => $c(WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::ISSUE_INTERNAL, WarehouseCapability::CUSTOMER_RETURNS, WarehouseCapability::STOCKTAKE, WarehouseCapability::ADJUST, WarehouseCapability::RESERVE),
            self::TRANSIT_HUB => $c(WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::DISPATCH_TRANSFERS, WarehouseCapability::CROSS_DOCK, WarehouseCapability::STOCKTAKE),
            self::OTHER => $c(WarehouseCapability::RECEIVE_TRANSFERS, WarehouseCapability::ISSUE_INTERNAL, WarehouseCapability::STOCKTAKE),
        };
    }
}
