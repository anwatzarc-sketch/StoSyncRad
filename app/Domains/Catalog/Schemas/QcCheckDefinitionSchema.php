<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\QcCheckType;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** One check inside a QC checklist template (stored as JSON). */
final class QcCheckDefinitionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'qc_checklist_templates';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(60)->required()->pattern('/^[a-z0-9_]{1,60}$/')->label('Code'),
            'label' => Property::string(200)->required()->trim()->label('Label'),
            'type' => Property::enum(QcCheckType::class)->required()->label('Answer type'),
            'required' => Property::boolean()->default(true)->label('Required'),
            'min' => Property::decimal(20, 6)->nullable()->label('Minimum (numeric)'),
            'max' => Property::decimal(20, 6)->nullable()->label('Maximum (numeric)'),
            'choices' => Property::arrayOf(Property::string(80))->nullable()->max(30)->label('Choices'),
            'fail_values' => Property::arrayOf(Property::string(80))->nullable()->max(30)->label('Answers that fail the check'),
        ];
    }
}
