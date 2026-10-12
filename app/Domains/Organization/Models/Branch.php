<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\IdentityAccess\Models\Person;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Schemas\BranchSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Company-owned operating site (Chapter 06 §6.2).
 *
 * @property string $code
 * @property string $name
 * @property BranchKind $kind
 * @property string|null $parent_branch_id
 * @property string|null $timezone
 * @property BranchStatus $status
 */
final class Branch extends BaseModel
{
    protected $table = 'branches';

    protected static ?string $schema = BranchSchema::class;

    protected function casts(): array
    {
        return [
            'kind' => BranchKind::class,
            'status' => BranchStatus::class,
            'address' => 'array',
            'contact' => 'array',
            'metadata' => 'array',
            'opened_on' => 'immutable_date',
            'closed_on' => 'immutable_date',
        ];
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_branch_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'manager_person_id');
    }
}
