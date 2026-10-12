<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Models;

use App\Support\Eloquent\BaseModel;

/** Private, expiring file produced by a user-initiated export. */
final class ExportFile extends BaseModel
{
    protected $table = 'export_files';

    protected $fillable = ['kind', 'disk', 'path', 'filename', 'mime_type', 'size', 'owner_account_id', 'report_run_id', 'expires_at', 'last_downloaded_at', 'download_count'];

    protected function casts(): array
    {
        return [
            'kind' => \App\Domains\DataExchange\Enums\ExportFileKind::class,
            'expires_at' => 'immutable_datetime',
            'last_downloaded_at' => 'immutable_datetime',
            'size' => 'integer',
            'download_count' => 'integer',
        ];
    }
}
