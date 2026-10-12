<?php

declare(strict_types=1);

namespace App\Domains\Sales\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Staff price a quotation and send it to the customer. */
final class QuotationPriceSchema extends TableSchema
{
    public static function table(): string
    {
        return 'quotations';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class, 'status', ['active'])->virtual()->label('Fulfilling warehouse'),
            'valid_until' => Property::date()->nullable()->virtual()->label('Valid until'),
            'currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->virtual()->label('Currency'),
            'lines' => Property::arrayOf(QuotationPriceLineSchema::class)->nullable()->max(1000)->virtual()->label('Prices'),
            'comment' => Property::text(2000)->nullable()->trim()->virtual()->label('Note to the customer'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
