<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Approval with optional per-line approved base quantities (stock requests). */
final class LineApprovalSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_requests';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(LineApprovalItemSchema::class)->nullable()->max(2000)->virtual()->label('Approved quantities'),
            'comment' => Property::text(2000)->nullable()->virtual()->label('Comment'),
            'override_reason' => Property::text(2000)->nullable()->virtual()->label('SoD override reason'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
