<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class RoleSchema extends TableSchema
{
    public static function table(): string
    {
        return 'roles';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(64)->requiredOnCreate()->trim()->pattern('/^[a-z0-9_]{2,64}$/')->unique()->immutableAfter('active', 'archived')->searchable()->label('Code'),
            'name' => Property::string(120)->required()->trim()->searchable()->sortable()->label('Name'),
            'description' => Property::text(2000)->nullable()->trim()->label('Description'),
            'is_system' => Property::boolean()->serverControlled(),
            'is_template_origin' => Property::boolean()->serverControlled(),
            'status' => Property::enum(RoleStatus::class)->serverControlled()->filterable()->label('Status'),
            'permissions' => Property::arrayOf(Property::string(120)->pattern('/^[a-z_]+\.[a-z_]+\.[a-z_]+$/'))->nullable()->notReportable()->label('Permission codes')->virtual(),
            'permission_constraints' => Property::json()->nullable()->notReportable()->label('Per-permission constraints')->virtual(),
        ];
    }
}
