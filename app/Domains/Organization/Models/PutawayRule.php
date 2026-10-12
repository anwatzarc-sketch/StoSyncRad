<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Enums\PutawayAppliesTo;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Schemas\PutawayRuleSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suggests a destination bin for received/transferred stock (Chapter 06 §6.4).
 *
 * @property string $warehouse_id
 * @property PutawayAppliesTo $applies_to
 * @property string $target_id
 * @property StorageLocationUsage $source_usage
 * @property string $destination_location_id
 * @property int $priority
 * @property bool $is_active
 */
final class PutawayRule extends BaseModel
{
    protected $table = 'putaway_rules';

    protected static ?string $schema = PutawayRuleSchema::class;

    protected function casts(): array
    {
        return ['applies_to' => PutawayAppliesTo::class, 'source_usage' => StorageLocationUsage::class, 'priority' => 'integer', 'is_active' => 'boolean'];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'destination_location_id');
    }
}
