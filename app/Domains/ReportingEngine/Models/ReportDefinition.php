<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Models;

use App\Support\Eloquent\BaseModel;

/** Saved report definition (versioned; system seeds are read-only but cloneable). */
final class ReportDefinition extends BaseModel
{
    protected $table = 'report_definitions';

    protected $fillable = ['code', 'name', 'description', 'owner_account_id', 'visibility', 'dataset_code', 'definition', 'definition_version', 'schema_version', 'is_system_seed', 'is_active', 'shared_role_ids', 'tags', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'visibility' => \App\Domains\ReportingEngine\Enums\ReportVisibility::class,
            'definition' => 'array',
            'definition_version' => 'integer',
            'schema_version' => 'integer',
            'is_system_seed' => 'boolean',
            'is_active' => 'boolean',
            'shared_role_ids' => \App\Support\Eloquent\Casts\PgTextArray::class,
            'tags' => \App\Support\Eloquent\Casts\PgTextArray::class,
        ];
    }
}
