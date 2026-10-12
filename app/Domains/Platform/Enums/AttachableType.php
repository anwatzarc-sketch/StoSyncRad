<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Domains\Organization\Enums\DocumentType;
use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/**
 * Records that may carry private attachments: every document type plus master records.
 */
enum AttachableType: string implements SystemEnum
{
    use HasSystemEnum;

    case ITEM = 'item';
    case PARTNER = 'partner';
    case WAREHOUSE = 'warehouse';
    case BRANCH = 'branch';
    case COMPANY = 'company';
    case PURCHASE_REQUEST = 'purchase_request';
    case PURCHASE_ORDER = 'purchase_order';
    case GOODS_RECEIPT = 'goods_receipt';
    case SUPPLIER_RETURN = 'supplier_return';
    case TRANSFER_ORDER = 'transfer_order';
    case TRANSFER_DISPATCH = 'transfer_dispatch';
    case TRANSFER_RECEIPT = 'transfer_receipt';
    case TRANSFER_DISCREPANCY = 'transfer_discrepancy';
    case STOCK_REQUEST = 'stock_request';
    case STOCK_ISSUE = 'stock_issue';
    case INTERNAL_RETURN = 'internal_return';
    case SALES_ORDER = 'sales_order';
    case SHIPMENT = 'shipment';
    case CUSTOMER_RETURN = 'customer_return';
    case STOCKTAKE = 'stocktake';
    case STOCK_ADJUSTMENT = 'stock_adjustment';
    case QUALITY_CHECK = 'quality_check';
    case OPENING_BALANCE = 'opening_balance';
    case RECALL = 'recall';
    case CONDITION_CHANGE = 'condition_change';
    case SCRAP = 'scrap';
    case PAYMENT_CONFIRMATION = 'payment_confirmation';
    case DELIVERY_ACKNOWLEDGEMENT = 'delivery_acknowledgement';

    public static function fromDocumentType(DocumentType $type): ?self
    {
        return self::tryFrom($type->value);
    }
}
