<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Register a lot ahead of receipt. */
final class LotRegisterSchema extends TableSchema
{
    public static function table(): string
    {
        return 'inventory_lots';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->virtual()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->virtual()->label('Variant'),
            'lot_number' => Property::string(80)->nullable()->trim()->pattern('/^[A-Za-z0-9._\/-]{1,80}$/')->virtual()->label('Lot / batch (blank = generate)'),
            'supplier_id' => Property::uuid()->nullable()->references(Partner::class)->virtual()->label('Supplier'),
            'supplier_lot_reference' => Property::string(80)->nullable()->trim()->virtual()->label('Supplier lot reference'),
            'manufacture_date' => Property::date()->nullable()->virtual()->label('Manufactured'),
            'expiry_date' => Property::date()->nullable()->after('manufacture_date')->virtual()->label('Expiry'),
        ];
    }
}
