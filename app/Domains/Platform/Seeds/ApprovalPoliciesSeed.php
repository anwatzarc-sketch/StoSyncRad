<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Enums\ApprovalValueBasis;
use App\Domains\Organization\Enums\DocumentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Default approval policies (Chapter 04 §4.8): one approver below a value threshold, two above.
 * Thresholds are expressed in the base currency and are meant to be tuned in Setup Step 11.
 */
final class ApprovalPoliciesSeed extends Seed
{
    public function name(): string
    {
        return 'approval_policies';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $types = [
            [DocumentType::PURCHASE_ORDER, '500000', ApprovalValueBasis::DOCUMENT_TOTAL],
            [DocumentType::PURCHASE_REQUEST, '500000', ApprovalValueBasis::DOCUMENT_TOTAL],
            [DocumentType::STOCK_ADJUSTMENT, '100000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::TRANSFER_ORDER, '1000000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::STOCK_REQUEST, '200000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::STOCK_ISSUE, '200000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::STOCKTAKE, '100000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::OPENING_BALANCE, '0', ApprovalValueBasis::COST_VALUE],
            [DocumentType::SCRAP, '50000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::SUPPLIER_RETURN, '500000', ApprovalValueBasis::COST_VALUE],
            [DocumentType::ALLOCATION_PLAN, '0', ApprovalValueBasis::COST_VALUE],
            [DocumentType::GOODS_RECEIPT, '0', ApprovalValueBasis::DOCUMENT_TOTAL],
            [DocumentType::INTERNAL_RETURN, '0', ApprovalValueBasis::COST_VALUE],
            [DocumentType::CONDITION_CHANGE, '0', ApprovalValueBasis::COST_VALUE],
        ];
        $inserted = 0;
        foreach ($types as [$type, $threshold, $basis]) {
            if (DB::table('approval_policies')->where('document_type', $type->value)->exists()) {
                continue;
            }
            $permission = SaveRoleAction::approvePermissionFor($type);
            $tiers = $threshold === '0'
                ? [['min_value' => '0', 'max_value' => null, 'required_approvals' => 1, 'required_permission' => $permission, 'sequential' => false]]
                : [
                    ['min_value' => '0', 'max_value' => $threshold, 'required_approvals' => 1, 'required_permission' => $permission, 'sequential' => false],
                    ['min_value' => $threshold, 'max_value' => null, 'required_approvals' => 2, 'required_permission' => $permission, 'sequential' => true],
                ];
            DB::table('approval_policies')->insert([
                'id' => (string) Str::uuid7(), 'document_type' => $type->value, 'value_basis' => $basis->value, 'currency' => null,
                'tiers' => json_encode($tiers), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $inserted++;
        }

        return ['inserted' => $inserted];
    }
}
