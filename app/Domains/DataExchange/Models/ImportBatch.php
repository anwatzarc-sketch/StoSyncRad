<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Models;

use App\Support\Eloquent\BaseModel;

/** Uploaded import file: validate → preview → confirm → process. */
final class ImportBatch extends BaseModel
{
    protected $table = 'import_batches';

    protected $fillable = ['import_type', 'status', 'file_path', 'original_filename', 'file_checksum', 'row_count', 'valid_count', 'invalid_count', 'imported_count', 'options', 'confirmed_by', 'confirmed_at', 'started_at', 'finished_at', 'error', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'import_type' => \App\Domains\DataExchange\Enums\ImportType::class,
            'status' => \App\Domains\DataExchange\Enums\ImportBatchStatus::class,
            'options' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'row_count' => 'integer',
            'valid_count' => 'integer',
            'invalid_count' => 'integer',
            'imported_count' => 'integer',
        ];
    }
}
