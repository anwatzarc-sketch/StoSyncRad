<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Models;

use App\Support\Eloquent\BaseModel;

/** Append-only history of a report definition. */
final class ReportDefinitionVersion extends BaseModel
{
    protected $table = 'report_definition_versions';

    public $timestamps = false;

    protected $fillable = ['report_definition_id', 'definition_version', 'name', 'definition', 'changed_by', 'change_note', 'created_at'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'definition_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
