<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Eloquent\BaseModel;

/**
 * Separation-of-duties rule (Chapter 04 §4.7).
 *
 * @property string $code
 * @property DocumentType $document_type
 * @property DocumentAction $first_action   action already recorded on the document
 * @property DocumentAction $second_action  action being attempted
 * @property SodRuleType $rule
 * @property array<string,mixed>|null $threshold  {"min_value": "10000"} or {"min_quantity": "1000"}
 * @property bool $is_active
 */
final class SodRule extends BaseModel
{
    protected $table = 'sod_rules';

    protected $fillable = ['code', 'document_type', 'first_action', 'second_action', 'rule', 'threshold', 'description', 'is_active', 'is_system', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class, 'first_action' => DocumentAction::class, 'second_action' => DocumentAction::class, 'rule' => SodRuleType::class, 'threshold' => 'array', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }
}
