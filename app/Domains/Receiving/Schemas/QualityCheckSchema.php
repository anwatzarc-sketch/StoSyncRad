<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Payload of POST /goods-receipts/{id}/lines/{line}/quality-checks. */
final class QualityCheckSchema extends TableSchema
{
    public static function table(): string
    {
        return 'quality_checks';
    }

    protected static function define(): array
    {
        return [
            'answers' => Property::json()->required()->label('Answers', '{check_code: answer}'),
            'inspected_quantity' => Property::quantity()->nullable()->nonNegative()->label('Inspected quantity'),
            'failed_quantity' => Property::quantity()->default('0')->nonNegative()->label('Failed quantity'),
            'notes' => Property::text(2000)->nullable()->label('Notes'),
        ];
    }
}
