<?php

declare(strict_types=1);

namespace App\Domains\Organization\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/**
 * Every numbered business document (Chapter 08 §8.13, Appendix A).
 */
enum DocumentType: string implements SystemEnum
{
    use HasSystemEnum;

    case PURCHASE_REQUEST = 'purchase_request';
    case PURCHASE_ORDER = 'purchase_order';
    case GOODS_RECEIPT = 'goods_receipt';
    case SUPPLIER_RETURN = 'supplier_return';
    case ADVANCE_SHIPMENT_NOTICE = 'advance_shipment_notice';
    case ALLOCATION_PLAN = 'allocation_plan';
    case TRANSFER_ORDER = 'transfer_order';
    case TRANSFER_DISPATCH = 'transfer_dispatch';
    case TRANSFER_RECEIPT = 'transfer_receipt';
    case STOCK_REQUEST = 'stock_request';
    case PICK_LIST = 'pick_list';
    case STOCK_ISSUE = 'stock_issue';
    case INTERNAL_RETURN = 'internal_return';
    case SALES_ORDER = 'sales_order';
    case SHIPMENT = 'shipment';
    case CUSTOMER_RETURN = 'customer_return';
    case STOCKTAKE = 'stocktake';
    case STOCK_ADJUSTMENT = 'stock_adjustment';
    case RELOCATION = 'relocation';
    case CONDITION_CHANGE = 'condition_change';
    case SCRAP = 'scrap';
    case OPENING_BALANCE = 'opening_balance';
    case STOCK_MOVEMENT = 'stock_movement';
    case RECALL = 'recall';
    case QUOTATION = 'quotation';
    case INVOICE = 'invoice';
    case PAYMENT = 'payment';
    case PAYMENT_CONFIRMATION = 'payment_confirmation';

    /** Default numbering prefix seeded in Setup Step 10. */
    public function defaultPrefix(): string
    {
        return match ($this) {
            self::PURCHASE_REQUEST => 'PR',
            self::PURCHASE_ORDER => 'PO',
            self::GOODS_RECEIPT => 'GRN',
            self::SUPPLIER_RETURN => 'SRT',
            self::ADVANCE_SHIPMENT_NOTICE => 'ASN',
            self::ALLOCATION_PLAN => 'ALP',
            self::TRANSFER_ORDER => 'TRF',
            self::TRANSFER_DISPATCH => 'TDN',
            self::TRANSFER_RECEIPT => 'TRN',
            self::STOCK_REQUEST => 'REQ',
            self::PICK_LIST => 'PCK',
            self::STOCK_ISSUE => 'ISS',
            self::INTERNAL_RETURN => 'IRT',
            self::SALES_ORDER => 'SO',
            self::SHIPMENT => 'SHP',
            self::CUSTOMER_RETURN => 'RMA',
            self::STOCKTAKE => 'STK',
            self::STOCK_ADJUSTMENT => 'ADJ',
            self::RELOCATION => 'RLC',
            self::CONDITION_CHANGE => 'CCH',
            self::SCRAP => 'SCR',
            self::OPENING_BALANCE => 'OPB',
            self::STOCK_MOVEMENT => 'MOV',
            self::RECALL => 'RCL',
            self::QUOTATION => 'QUO',
            self::INVOICE => 'INV',
            self::PAYMENT => 'RCT',
            self::PAYMENT_CONFIRMATION => 'PCF',
        };
    }
}
