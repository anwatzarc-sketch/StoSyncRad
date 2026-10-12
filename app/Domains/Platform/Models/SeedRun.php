<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\SeedRunStatus;
use App\Support\Eloquent\BaseModel;

/**
 * Versioned, idempotent seed execution record (Chapter 02 §2.6).
 *
 * @property string $seed
 * @property string $version
 * @property string $checksum
 * @property SeedRunStatus $status
 * @property array<string,mixed>|null $result
 */
final class SeedRun extends BaseModel
{
    protected $table = 'seed_runs';

    protected $fillable = ['seed', 'version', 'checksum', 'status', 'started_at', 'finished_at', 'result', 'error'];

    protected function casts(): array
    {
        return [
            'status' => SeedRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'result' => 'array',
        ];
    }
}
