<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Models;

use App\Domains\Fulfillment\Enums\PickListStatus;
use App\Domains\Fulfillment\Enums\PickSourceType;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pick list with suggested positions per removal strategy. */
final class PickList extends BaseModel
{
    protected $table = 'pick_lists';

    protected $fillable = ['document_number', 'warehouse_id', 'pick_wave_id', 'source_type', 'source_id', 'status', 'assigned_to', 'completed_by', 'completed_at', 'created_by', 'version'];

    protected function casts(): array
    {
        return [
            'source_type' => PickSourceType::class,
            'status' => PickListStatus::class,
            'completed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PickListLine::class, 'pick_list_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }
}
