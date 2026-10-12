<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ReplenishmentMethod;
use App\Domains\Catalog\Schemas\ItemReorderRuleSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Replenishment rule; a warehouse rule overrides the company rule. */
final class ItemReorderRule extends BaseModel
{
    protected $table = 'item_reorder_rules';

    protected static ?string $schema = ItemReorderRuleSchema::class;

    protected function casts(): array
    {
        return [
            'replenishment_method' => ReplenishmentMethod::class,
            'lead_time_days' => 'integer',
            'review_period_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Organization\Models\Warehouse::class, 'warehouse_id');
    }
}
