<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\IdentityAccess\Enums\DocumentAction as A;
use App\Domains\IdentityAccess\Enums\SodRuleType as R;
use App\Domains\Organization\Enums\DocumentType as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Default separation-of-duties rules (Chapter 04 §4.7). Editable; client changes are preserved. */
final class SodRulesSeed extends Seed
{
    /** [code, document type, first action, second action, rule, threshold, description] */
    public static function data(): array
    {
        return [
            ['po_creator_not_approver', D::PURCHASE_ORDER, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'A purchase order creator cannot approve the same order.'],
            ['pr_requester_not_approver', D::PURCHASE_REQUEST, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'A purchase request creator cannot approve it.'],
            ['adjustment_creator_not_approver', D::STOCK_ADJUSTMENT, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'An adjustment creator cannot approve the same adjustment.'],
            ['stocktake_counter_not_approver', D::STOCKTAKE, A::COUNT, A::APPROVE, R::DIFFERENT_ACTOR_RECOMMENDED, ['min_value' => '10000'], 'A counter should not approve material variances from their own count.'],
            ['transfer_requester_not_dispatcher', D::TRANSFER_ORDER, A::CREATE, A::DISPATCH, R::DIFFERENT_ACTOR_RECOMMENDED, null, 'The transfer requester should not dispatch it.'],
            ['transfer_dispatcher_not_receiver', D::TRANSFER_ORDER, A::DISPATCH, A::RECEIVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'The dispatcher of a transfer cannot confirm its receipt.'],
            ['transfer_requester_not_approver', D::TRANSFER_ORDER, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_RECOMMENDED, null, 'The transfer requester should not approve it.'],
            ['reversal_requester_not_approver', D::STOCK_MOVEMENT, A::REQUEST, A::APPROVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'Reversal requester and approver must differ.'],
            ['issue_requester_not_approver', D::STOCK_REQUEST, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'A stock requester cannot approve their own request.'],
            ['opening_balance_preparer_not_approver', D::OPENING_BALANCE, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_REQUIRED, null, 'Opening balances are prepared and approved by different people.'],
            ['scrap_creator_not_approver', D::SCRAP, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_RECOMMENDED, null, 'Scrap requests should be approved by another person.'],
            ['allocation_creator_not_approver', D::ALLOCATION_PLAN, A::CREATE, A::APPROVE, R::DIFFERENT_ACTOR_RECOMMENDED, null, 'Allocation plans should be approved by another planner.'],
            ['receipt_poster_not_po_approver', D::GOODS_RECEIPT, A::APPROVE, A::POST, R::DIFFERENT_ACTOR_RECOMMENDED, null, 'The receipt approver should not also post it.'],
        ];
    }

    public function name(): string
    {
        return 'sod_rules';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $rows = array_map(static fn (array $r) => [
            'id' => (string) Str::uuid7(), 'code' => $r[0], 'document_type' => $r[1]->value, 'first_action' => $r[2]->value,
            'second_action' => $r[3]->value, 'rule' => $r[4]->value, 'threshold' => $r[5] === null ? null : json_encode($r[5]),
            'description' => $r[6], 'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ], self::data());
        $inserted = 0;
        foreach ($rows as $row) {
            if (! DB::table('sod_rules')->where('code', $row['code'])->exists()
                && ! DB::table('sod_rules')->where('document_type', $row['document_type'])->where('first_action', $row['first_action'])->where('second_action', $row['second_action'])->exists()) {
                DB::table('sod_rules')->insert($row);
                $inserted++;
            }
        }

        return ['inserted' => $inserted];
    }
}
