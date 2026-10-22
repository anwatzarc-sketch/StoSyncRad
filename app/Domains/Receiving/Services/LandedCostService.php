<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Services;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\ValuationLayerStatus;
use App\Domains\Inventory\Posting\BalanceLocker;
use App\Domains\Inventory\Services\ValuationService;
use App\Domains\Organization\Models\InventoryLocation;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Receiving\Enums\GoodsReceiptStatus;
use App\Domains\Receiving\Enums\LandedCostBasis;
use App\Domains\Receiving\Models\GoodsReceipt;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Landed-cost allocation (Chapter 07 §7.9, feature.landed_cost): freight/duty/handling added after a
 * receipt is distributed over its lines by value or quantity. The share still on hand is
 * capitalized (FIFO layer cost raised, or the moving-average projection revalued); the share already
 * consumed is expensed as a valuation variance. Quantities never change.
 */
final class LandedCostService
{
    use ManagesDocuments;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly ValuationService $valuation,
        private readonly CategoryPolicyResolver $policies,
        private readonly BalanceLocker $locker,
    ) {}

    /** @param  array{amount: string, basis?: string, description: string, currency?: ?string}  $data */
    public function allocate(string $goodsReceiptId, UserAccount $actor, ?string $idempotencyKey, array $data): PostingOutcome
    {
        if (! $this->config->feature('feature.landed_cost')) {
            throw DomainException::featureDisabled('feature.landed_cost');
        }

        return $this->pipeline->run($actor, 'inventory.landed_cost.allocate', $idempotencyKey, ['goods_receipt_id' => $goodsReceiptId] + $data,
            function (PostingContext $ctx) use ($goodsReceiptId, $actor, $data) {
                $gr = $this->lockDocument(GoodsReceipt::class, $goodsReceiptId);
                $this->authorizeWarehouse($actor, 'inventory.landed_cost.manage', $gr->warehouse_id);
                if ($gr->status !== GoodsReceiptStatus::POSTED || $gr->stock_movement_id === null) {
                    throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Landed costs apply to posted receipts.');
                }
                $amount = D::exact((string) ($data['amount'] ?? '0'), D::MONEY_SCALE);
                if (! D::isPositive($amount) || trim((string) ($data['description'] ?? '')) === '') {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter a positive amount and a description.', ['field' => 'amount']);
                }
                $byQuantity = ($data['basis'] ?? LandedCostBasis::VALUE->value) === LandedCostBasis::QUANTITY->value;
                $lines = DB::table('stock_movement_lines as ml')->where('ml.stock_movement_id', $gr->stock_movement_id)
                    ->join('goods_receipt_lines as gl', 'gl.id', '=', 'ml.source_line_id')->orderBy('ml.id')
                    ->get(['ml.id', 'ml.item_id', 'ml.item_variant_id', 'ml.quantity', 'ml.total_cost_snapshot', 'ml.destination_inventory_location_id', 'gl.id as gr_line_id']);
                if ($lines->isEmpty()) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The receipt has no stocked lines.');
                }
                $weights = $lines->map(fn ($l) => $byQuantity ? (string) $l->quantity : (string) $l->total_cost_snapshot)->all();
                $totalWeight = D::sum($weights, D::MONEY_SCALE);
                if (! D::isPositive($totalWeight)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The receipt has no value to allocate by; use the quantity basis.');
                }
                $groupId = (string) Str::uuid7();
                $allocated = '0';
                $rows = [];
                $targets = $this->lockTargets($lines);
                foreach ($lines->values() as $i => $l) {
                    $share = $i === $lines->count() - 1 ? D::sub($amount, $allocated, D::MONEY_SCALE)
                        : D::div(D::mul($amount, $weights[$i], D::MONEY_SCALE + 6), $totalWeight, D::MONEY_SCALE);
                    $allocated = D::add($allocated, $share, D::MONEY_SCALE);
                    [$capitalized, $expensed] = $this->apply($l, $share, $targets[$l->id]);
                    $rows[] = ['id' => (string) Str::uuid7(), 'allocation_group_id' => $groupId, 'goods_receipt_id' => $gr->id, 'goods_receipt_line_id' => $l->gr_line_id,
                        'stock_movement_line_id' => $l->id, 'item_id' => $l->item_id, 'description' => mb_substr((string) $data['description'], 0, 200),
                        'currency' => $data['currency'] ?? null, 'allocated_value' => $share, 'capitalized_value' => $capitalized, 'expensed_value' => $expensed,
                        'created_by' => $actor->id, 'created_at' => now()];
                }
                DB::table('landed_cost_allocations')->insert($rows);
                $ctx->audit('inventory.landed_cost.allocated', 'goods_receipts', $gr->id, null, ['amount' => $amount, 'basis' => $byQuantity ? 'quantity' : 'value', 'group' => $groupId]);
                $ctx->publish('inventory.landed_cost.allocated', 'goods_receipts', $gr->id, ['document_number' => $gr->document_number, 'amount' => $amount]);

                return new PostingOutcome(['allocation_group_id' => $groupId, 'goods_receipt_id' => $gr->id, 'amount' => $amount,
                    'capitalized' => D::sum(array_column($rows, 'capitalized_value'), D::MONEY_SCALE), 'expensed' => D::sum(array_column($rows, 'expensed_value'), D::MONEY_SCALE),
                    'lines' => $rows], 201);
            }, failureAudit: ['inventory.landed_cost.failed', 'goods_receipts', $goodsReceiptId]);
    }

    /**
     * Lock everything the allocation updates in the same order postings use — items, then stock
     * positions, cost projections and FIFO layers — so it cannot deadlock with a concurrent posting.
     * Returns, per ledger line, the position that receives the capitalized value (the largest
     * on-hand position at the line's destination), or null when none is left.
     *
     * @param  Collection<int, object>  $lines
     * @return array<string, ?string>
     */
    private function lockTargets(Collection $lines): array
    {
        $itemIds = $lines->pluck('item_id')->unique()->sort(SORT_STRING)->values()->all();
        Item::query()->forceIndex('PRIMARY')->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->pluck('id');

        $targets = [];
        $projections = [];
        foreach ($lines as $l) {
            $variant = Sentinel::orId($l->item_variant_id);
            $targets[$l->id] = DB::table('inventory_balances')->where('inventory_location_id', $l->destination_inventory_location_id)
                ->where('item_id', $l->item_id)->where('item_variant_id', $variant)->where('quantity_on_hand', '>', 0)
                ->orderByDesc('quantity_on_hand')->orderBy('id')->value('id');
            $location = InventoryLocation::query()->findOrFail($l->destination_inventory_location_id);
            $key = [$l->item_id, $variant, $this->valuation->bucket($location) ?? Sentinel::UUID];
            $projections[implode('|', $key)] = $key;
        }
        $this->locker->lockByIds(array_values(array_filter($targets)));

        ksort($projections, SORT_STRING);
        foreach ($projections as $key) {
            DB::selectOne('SELECT id FROM item_cost_projections FORCE INDEX (item_cost_projections_identity_uq)
                            WHERE item_id = ? AND item_variant_id = ? AND warehouse_id = ? FOR UPDATE', $key);
        }

        $layerIds = DB::table('valuation_layers')->whereIn('stock_movement_line_id', $lines->pluck('id')->all())->pluck('id')->sort(SORT_STRING)->values()->all();
        if ($layerIds !== []) {
            DB::table('valuation_layers')->forceIndex('PRIMARY')->whereIn('id', $layerIds)->orderBy('id')->lockForUpdate()->pluck('id');
        }

        return $targets;
    }

    /** @return array{0: string, 1: string} [capitalized, expensed] */
    private function apply(object $line, string $share, ?string $balanceId): array
    {
        $item = Item::query()->findOrFail($line->item_id);
        $location = InventoryLocation::query()->findOrFail($line->destination_inventory_location_id);
        $bucket = $this->valuation->bucket($location) ?? Sentinel::UUID;
        $variant = Sentinel::orId($line->item_variant_id);
        $projection = DB::selectOne('SELECT * FROM item_cost_projections WHERE item_id = ? AND item_variant_id = ? AND warehouse_id = ? FOR UPDATE',
            [$item->id, $variant, $bucket]);
        $method = $this->policies->costingMethod($item);
        $received = (string) $line->quantity;
        if ($method === CostingMethod::FIFO) {
            $layer = DB::selectOne('SELECT * FROM valuation_layers WHERE stock_movement_line_id = ? FOR UPDATE', [$line->id]);
            $remaining = $layer !== null ? (string) $layer->remaining_quantity : '0';
        } else {
            $remaining = $projection !== null ? D::min(D::max((string) $projection->quantity, '0'), $received) : '0';
        }
        $capitalized = $method === CostingMethod::STANDARD ? '0' : D::div(D::mul($share, $remaining, D::MONEY_SCALE + 6), $received, D::MONEY_SCALE);
        $expensed = D::sub($share, $capitalized, D::MONEY_SCALE);
        if (D::isPositive($capitalized) && $projection !== null) {
            $total = D::add((string) $projection->total_value, $capitalized, D::MONEY_SCALE);
            $avg = D::isPositive((string) $projection->quantity) ? D::div($total, (string) $projection->quantity, D::COST_SCALE) : (string) $projection->average_cost;
            DB::update('UPDATE item_cost_projections SET total_value = ?, average_cost = ?, version = version + 1, updated_at = ? WHERE id = ?', [$total, $avg, now(), $projection->id]);
            if ($bucket === Sentinel::UUID && $variant === Sentinel::UUID) {
                DB::update('UPDATE items SET current_average_cost = ? WHERE id = ?', [$avg, $item->id]);
            }
            if ($method === CostingMethod::FIFO && isset($layer) && $layer !== null && D::isPositive($remaining)) {
                DB::update('UPDATE valuation_layers SET unit_cost = unit_cost + ?, updated_at = ? WHERE id = ? AND status = ?',
                    [D::div($capitalized, $remaining, D::COST_SCALE), now(), $layer->id, ValuationLayerStatus::OPEN->value]);
            }
            if ($balanceId !== null) {
                DB::update('UPDATE inventory_balances SET total_value_projection = total_value_projection + ?, updated_at = ? WHERE id = ? AND quantity_on_hand > 0',
                    [$capitalized, now(), $balanceId]);
            }
        }
        if (D::isPositive($expensed)) {
            DB::table('valuation_variances')->insert(['id' => (string) Str::uuid7(), 'item_id' => $item->id, 'stock_movement_line_id' => $line->id,
                'standard_unit_cost' => '0', 'actual_unit_cost' => D::div($expensed, $received, D::COST_SCALE), 'quantity' => $received,
                'variance_value' => $expensed, 'created_at' => now()]);
        }

        return [$capitalized, $expensed];
    }
}
