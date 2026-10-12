<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** POST /transfers/{id}/approvals — optional per-line approved base quantities (default: as requested). */
final class TransferApprovalSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_orders';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(TransferApprovalLineSchema::class)->nullable()->max(500)->virtual()->label('Approved quantities'),
            'comment' => Property::text(2000)->nullable()->virtual()->label('Comment'),
            'override_reason' => Property::text(2000)->nullable()->virtual()->label('SoD override reason'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
