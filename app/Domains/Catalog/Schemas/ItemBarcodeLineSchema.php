<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\BarcodeSymbology;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Nested barcode line on the item form (item id is server-set). */
final class ItemBarcodeLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_barcodes';
    }

    protected static function define(): array
    {
        return [
            'id' => Property::uuid()->nullable()->label('Existing barcode id')->virtual(),
            'barcode' => Property::barcode()->required()->trim()->unique()->label('Barcode'),
            'symbology' => Property::enum(BarcodeSymbology::class)->default(BarcodeSymbology::CODE128->value)->label('Symbology'),
            'item_variant_id' => Property::uuid()->nullable()->label('Variant'),
            'packaging_index' => Property::integer()->nullable()->min(0)->label('Packaging (index in packagings)')->virtual(),
            'is_primary' => Property::boolean()->default(false)->label('Primary'),
        ];
    }
}
