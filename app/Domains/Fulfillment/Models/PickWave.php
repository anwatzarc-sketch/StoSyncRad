<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Domains\Fulfillment\Enums\PickListStatus;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Batch of pick lists picked together. */
final class PickWave extends BaseModel
{
    protected $table = 'pick_waves';

    protected $fillable = ['document_number', 'warehouse_id', 'status', 'assigned_to', 'created_by'];

    protected function casts(): array
    {
        return [
            'status' => PickListStatus::class,
        ];
    }

    public function lists(): HasMany
    {
        return $this->hasMany(PickList::class, 'pick_wave_id');
    }
}
