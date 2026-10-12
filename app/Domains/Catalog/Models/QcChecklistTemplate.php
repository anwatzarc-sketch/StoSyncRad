<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Schemas\QcChecklistTemplateSchema;
use App\Support\Eloquent\BaseModel;

/** Quality-control checklist template. */
final class QcChecklistTemplate extends BaseModel
{
    protected $table = 'qc_checklist_templates';

    protected static ?string $schema = QcChecklistTemplateSchema::class;

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
