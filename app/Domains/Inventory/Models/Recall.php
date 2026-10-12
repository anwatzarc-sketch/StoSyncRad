<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\RecallStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lot recall locking stock company-wide (Chapter 07 §7.10). */
final class Recall extends BaseModel
{
    protected $table = 'recalls';

    protected $fillable = ['document_number', 'reason', 'status', 'initiated_by', 'initiated_at', 'closed_by', 'closed_at', 'closure_note', 'version'];

    protected function casts(): array
    {
        return [
            'status' => RecallStatus::class,
            'initiated_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function lots(): HasMany
    {
        return $this->hasMany(RecallLot::class, 'recall_id');
    }
}
