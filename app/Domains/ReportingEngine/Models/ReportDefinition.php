<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Models;

use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Support\Eloquent\BaseModel;
use App\Support\Eloquent\Casts\JsonList;

/** Saved report definition (versioned; system seeds are read-only but cloneable). */
final class ReportDefinition extends BaseModel
{
    protected $table = 'report_definitions';

    protected $fillable = ['code', 'name', 'description', 'owner_account_id', 'visibility', 'dataset_code', 'definition', 'definition_version', 'schema_version', 'is_system_seed', 'is_active', 'shared_role_ids', 'tags', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'visibility' => ReportVisibility::class,
            'definition' => 'array',
            'definition_version' => 'integer',
            'schema_version' => 'integer',
            'is_system_seed' => 'boolean',
            'is_active' => 'boolean',
            'shared_role_ids' => JsonList::class,
            'tags' => JsonList::class,
        ];
    }
}
