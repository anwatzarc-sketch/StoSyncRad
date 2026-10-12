<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Config\ConfigScopeType;
use App\Domains\Platform\Config\ConfigValueType;
use App\Support\Eloquent\BaseModel;

/**
 * One stored configuration value at one scope (Chapter 03 §3.2).
 *
 * @property string $id
 * @property string $key
 * @property ConfigScopeType $scope_type
 * @property string|null $scope_id
 * @property string $value  JSON-encoded (secrets encrypted before encoding)
 * @property ConfigValueType $value_type
 * @property int $version
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property string|null $updated_by
 */
final class ConfigurationEntry extends BaseModel
{
    protected $table = 'configuration_entries';

    protected $fillable = ['key', 'scope_type', 'scope_id', 'value', 'value_type', 'version', 'effective_from', 'updated_by'];

    protected function casts(): array
    {
        return [
            'scope_type' => ConfigScopeType::class,
            'value_type' => ConfigValueType::class,
            'version' => 'integer',
            'effective_from' => 'immutable_datetime',
        ];
    }
}
