<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\ReconciliationRunStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One ledger-vs-projection reconciliation run. */
final class ReconciliationRun extends BaseModel
{
    protected $table = 'reconciliation_runs';

    protected $fillable = ['status', 'scope', 'positions_checked', 'findings_count', 'started_by', 'started_at', 'finished_at', 'error'];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationRunStatus::class,
            'scope' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'positions_checked' => 'integer',
            'findings_count' => 'integer',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ReconciliationFinding::class, 'reconciliation_run_id');
    }
}
