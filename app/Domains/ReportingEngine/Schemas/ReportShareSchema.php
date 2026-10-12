<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Schemas;

use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Report visibility and sharing. */
final class ReportShareSchema extends TableSchema
{
    public static function table(): string
    {
        return 'report_definitions';
    }

    protected static function define(): array
    {
        return [
            'visibility' => Property::enum(ReportVisibility::class)->required()->virtual()->label('Visibility'),
            'shared_role_ids' => Property::arrayOf(Property::uuid())->nullable()->max(100)->virtual()->label('Shared with roles'),
        ];
    }
}
