<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Models;

use App\Support\Eloquent\BaseModel;

/** One parsed row of an import batch with its validation errors. */
final class ImportRow extends BaseModel
{
    protected $table = 'import_rows';

    protected $fillable = ['import_batch_id', 'row_number', 'data', 'status', 'errors', 'result_id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'errors' => 'array',
            'status' => \App\Domains\DataExchange\Enums\ImportRowStatus::class,
            'row_number' => 'integer',
        ];
    }
}
