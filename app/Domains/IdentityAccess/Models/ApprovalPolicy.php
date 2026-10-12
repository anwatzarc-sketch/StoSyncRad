<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\ApprovalValueBasis;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Eloquent\BaseModel;

/**
 * Approval tiers per document type (Chapter 04 §4.8).
 *
 * tiers: [{"min_value": "0", "max_value": "10000", "required_approvals": 1, "required_permission": "procurement.purchase_order.approve", "sequential": false}, ...]
 *
 * @property DocumentType $document_type
 * @property ApprovalValueBasis $value_basis
 * @property string $currency
 * @property array<int, array<string,mixed>> $tiers
 * @property bool $is_active
 */
final class ApprovalPolicy extends BaseModel
{
    protected $table = 'approval_policies';

    protected $fillable = ['document_type', 'value_basis', 'currency', 'tiers', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class, 'value_basis' => ApprovalValueBasis::class, 'tiers' => 'array', 'is_active' => 'boolean'];
    }
}
