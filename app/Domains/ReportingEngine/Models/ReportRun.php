<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Models;

use App\Support\Eloquent\BaseModel;

/** One on-demand execution (metadata only — never results). */
final class ReportRun extends BaseModel
{
    protected $table = 'report_runs';

    protected $fillable = ['report_definition_id', 'definition_version', 'dataset_code', 'runner_account_id', 'output_format', 'parameters', 'authorized_scope_snapshot', 'status', 'started_at', 'finished_at', 'duration_ms', 'row_count', 'truncated', 'file_path', 'file_size', 'file_expires_at', 'error_code', 'error_message', 'backend_pid', 'correlation_id'];

    protected function casts(): array
    {
        return [
            'output_format' => \App\Domains\ReportingEngine\Enums\ReportOutputFormat::class,
            'status' => \App\Domains\ReportingEngine\Enums\ReportRunStatus::class,
            'parameters' => 'array',
            'authorized_scope_snapshot' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'file_expires_at' => 'immutable_datetime',
            'truncated' => 'boolean',
            'row_count' => 'integer',
            'duration_ms' => 'integer',
            'file_size' => 'integer',
        ];
    }
}
