<?php

declare(strict_types=1);

namespace App\Domains\Planning\Services;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Planning\Enums\AllocationPlanStatus as S;
use App\Domains\Planning\Enums\AllocationPolicy;
use App\Domains\Planning\Models\AllocationPlan;
use App\Domains\Planning\Models\AllocationPlanLine;
use App\Domains\Transfers\Services\TransferWorkflow;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Allocation plans (Chapter 08 §8.1): HQ distributes available central stock across destination
 * warehouses by policy (manual, proportional to forecast, fill to max, priority order) with
 * per-destination caps. The plan never moves stock; releasing it creates one approved transfer per
 * destination.
 */
final class AllocationPlanWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly DemandService $demand,
        private readonly ReplenishmentService $replenishment,
        private readonly TransferWorkflow $transfers,
    ) {}

    /** @param  array<string, mixed>  $data  validated against AllocationPlanSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): AllocationPlan
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $this->authorizeWarehouse($actor, 'planning.allocation_plan.create', null);
                $source = Warehouse::query()->findOrFail($data['warehouse_id']);
                $source->assertCapability(WarehouseCapability::ALLOCATE);
                $plan = new AllocationPlan;
                $plan->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::ALLOCATION_PLAN, $source->id), 'warehouse_id' => $source->id, 'branch_id' => $source->branch_id,
                    'status' => S::DRAFT, 'policy' => AllocationPolicy::from($data['policy'] ?? AllocationPolicy::MANUAL->value),
                    'created_by' => $actor->id, 'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $plan = $this->lockDocument(AllocationPlan::class, $id);
                $this->authorizeWarehouse($actor, 'planning.allocation_plan.update', null);
                $this->expectVersion($plan, $data['version'] ?? null);
                $this->assertStatus($plan, [S::DRAFT]);
                if (isset($data['policy'])) {
                    $plan->forceFill(['policy' => AllocationPolicy::from($data['policy'])]);
                }
                $plan->forceFill(['version' => $plan->version + 1]);
            }
            $plan->fill(array_intersect_key($data, array_flip(['period_start', 'period_end', 'document_date', 'priority', 'reference', 'notes'])));
            $plan->forceFill(['updated_by' => $actor->id])->save();
            if (array_key_exists('lines', $data)) {
                AllocationPlanLine::query()->where('allocation_plan_id', $plan->id)->delete();
                foreach (array_values((array) $data['lines']) as $i => $line) {
                    if ($line['destination_warehouse_id'] === $plan->warehouse_id) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A destination cannot be the source warehouse.', ['field' => "lines.$i.destination_warehouse_id"]);
                    }
                    $item = $this->lines->item($line['item_id']);
                    if (! $item->is_transferable) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is not transferable.", ['field' => "lines.$i.item_id"]);
                    }
                    AllocationPlanLine::query()->forceCreate([
                        'allocation_plan_id' => $plan->id, 'line_no' => $i + 1, 'destination_warehouse_id' => $line['destination_warehouse_id'],
                        'item_id' => $item->id, 'item_variant_id' => $line['item_variant_id'] ?? null, 'suggested_quantity' => '0',
                        'quantity' => D::exact((string) ($line['quantity'] ?? '0')), 'cap_quantity' => isset($line['cap_quantity']) ? D::exact((string) $line['cap_quantity']) : null,
                        'priority' => (int) ($line['priority'] ?? 100), 'notes' => $line['notes'] ?? null,
                    ]);
                }
            }
            $this->audit($actor, $id === null ? 'planning.allocation_plan.created' : 'planning.allocation_plan.updated', $plan, null, ['policy' => $plan->policy->value]);

            return $plan->refresh();
        });
    }

    /**
     * Compute suggested (and allocated) quantities per the plan's policy from source availability,
     * destination forecasts and reorder rules. Manual plans keep the entered quantities.
     *
     * @return array<int, array<string, mixed>> explanation per line
     */
    public function suggest(string $id, UserAccount $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $plan = $this->lockDocument(AllocationPlan::class, $id);
            $this->authorizeWarehouse($actor, 'planning.allocation_plan.update', null);
            $this->assertStatus($plan, [S::DRAFT]);
            $lines = AllocationPlanLine::query()->where('allocation_plan_id', $plan->id)->orderBy('priority')->orderBy('line_no')->lockForUpdate()->get();
            $explain = [];
            foreach ($lines->groupBy(fn ($l) => $l->item_id.'|'.$l->item_variant_id) as $group) {
                $first = $group->first();
                $available = $this->sourceAvailable($plan->warehouse_id, $first->item_id, $first->item_variant_id);
                $needs = [];
                foreach ($group as $line) {
                    $d = $this->demand->dailyDemand($line->item_id, $line->item_variant_id, $line->destination_warehouse_id);
                    $forecast = D::mul($d['daily'], (string) $d['horizon_days'], D::QTY_SCALE);
                    $rule = DB::table('item_reorder_rules')->where('item_id', $line->item_id)->where('warehouse_id', $line->destination_warehouse_id)
                        ->where('is_active', true)->first();
                    $projected = $rule !== null ? $this->replenishment->evaluate((object) ((array) $rule + ['sku' => null, 'item_name' => null, 'base_uom_id' => null]))['projected'] : '0';
                    $toMax = $rule?->max_qty !== null ? D::max('0', D::sub((string) $rule->max_qty, $projected)) : D::max('0', D::sub($forecast, $projected));
                    $needs[$line->id] = ['forecast' => $forecast, 'projected' => $projected, 'to_max' => $toMax, 'method' => $d['method']];
                }
                $alloc = $this->allocate($plan->policy, $group, $needs, $available);
                foreach ($group as $line) {
                    $q = $alloc[$line->id];
                    $line->forceFill(['suggested_quantity' => $q] + ($plan->policy === AllocationPolicy::MANUAL ? [] : ['quantity' => $q]))->save();
                    $explain[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'destination_warehouse_id' => $line->destination_warehouse_id,
                        'source_available' => $available, 'suggested' => $q, 'cap' => $line->cap_quantity] + $needs[$line->id];
                }
            }
            $this->audit($actor, 'planning.allocation_plan.suggested', $plan, null, ['lines' => count($explain)]);

            return $explain;
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): AllocationPlan
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $plan = $this->lockDocument(AllocationPlan::class, $id);
            $this->authorizeWarehouse($actor, 'planning.allocation_plan.submit', null);
            $this->expectVersion($plan, $version);
            if (! AllocationPlanLine::query()->where('allocation_plan_id', $plan->id)->where('quantity', '>', 0)->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Allocate a quantity on at least one line.');
            }
            $this->assertWithinAvailability($plan);
            $this->transition($plan, S::PENDING_APPROVAL, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::ALLOCATION_PLAN, $plan->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'planning.allocation_plan.submitted', $plan);

            return $plan;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): AllocationPlan
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $plan = $this->lockDocument(AllocationPlan::class, $id);
            $this->authorizeWarehouse($actor, 'planning.allocation_plan.approve', null);
            $this->expectVersion($plan, $version);
            $this->assertStatus($plan, [S::PENDING_APPROVAL]);
            $this->enforceSod(DocumentType::ALLOCATION_PLAN, DocumentAction::APPROVE, $this->documentActors($plan, DocumentType::ALLOCATION_PLAN), $actor, $plan->id, null, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::ALLOCATION_PLAN, $plan->id, $actor, '0', null, ['warehouse_id' => $plan->warehouse_id, 'branch_id' => $plan->branch_id], $comment);
            if ($complete) {
                $this->transition($plan, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            }
            $this->audit($actor, 'planning.allocation_plan.approval_recorded', $plan, null, ['fully_approved' => $complete], $comment);

            return $plan;
        });
    }

    /** Generate one approved transfer per destination. */
    public function release(string $id, UserAccount $actor, ?int $version = null): AllocationPlan
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $plan = $this->lockDocument(AllocationPlan::class, $id);
            $this->authorizeWarehouse($actor, 'planning.allocation_plan.release', null);
            $this->expectVersion($plan, $version);
            $this->assertStatus($plan, [S::APPROVED]);
            $this->assertWithinAvailability($plan);
            $lines = AllocationPlanLine::query()->where('allocation_plan_id', $plan->id)->where('quantity', '>', 0)->orderBy('line_no')->lockForUpdate()->get();
            $created = [];
            foreach ($lines->groupBy('destination_warehouse_id') as $destination => $group) {
                $to = $this->transfers->createApproved([
                    'source_warehouse_id' => $plan->warehouse_id, 'destination_warehouse_id' => $destination, 'reason' => "Allocation plan {$plan->document_number}",
                    'priority' => $plan->priority?->value, 'lines' => $group->map(fn ($l) => ['item_id' => $l->item_id, 'item_variant_id' => $l->item_variant_id,
                        'quantity' => $l->quantity, 'uom_id' => $this->lines->item($l->item_id, false)->base_uom_id, 'notes' => $l->notes])->values()->all(),
                ], $actor, $plan->id, $plan->approved_by ?? $actor->id);
                AllocationPlanLine::query()->whereIn('id', $group->pluck('id'))->update(['transfer_order_id' => $to->id, 'updated_at' => now()]);
                $created[] = $to->document_number;
            }
            $this->transition($plan, S::RELEASED, ['released_by' => $actor->id, 'released_at' => now()]);
            $this->audit($actor, 'planning.allocation_plan.released', $plan, null, ['transfers' => $created]);
            $this->publish('planning.allocation_plan.released', $plan, ['document_number' => $plan->document_number, 'transfers' => $created]);

            return $plan;
        });
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): AllocationPlan
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $plan = $this->lockDocument(AllocationPlan::class, $id);
            $this->authorizeWarehouse($actor, 'planning.allocation_plan.cancel', null);
            $this->expectVersion($plan, $version);
            $this->transition($plan, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'planning.allocation_plan.cancelled', $plan, null, null, $reason);

            return $plan;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AllocationPlanLine>  $group  lines of one item, priority order
     * @param  array<string, array{forecast: string, to_max: string}>  $needs
     * @return array<string, string>
     */
    private function allocate(AllocationPolicy $policy, $group, array $needs, string $available): array
    {
        $cap = fn (AllocationPlanLine $l, string $q) => $l->cap_quantity !== null ? D::min($q, D::exact((string) $l->cap_quantity)) : $q;
        $out = [];
        switch ($policy) {
            case AllocationPolicy::MANUAL:
                foreach ($group as $l) {
                    $out[$l->id] = $cap($l, D::min(D::max($needs[$l->id]['to_max'], '0'), $available));
                }
                break;
            case AllocationPolicy::PROPORTIONAL_TO_FORECAST:
                $total = D::sum(array_map(fn ($l) => $needs[$l->id]['forecast'], $group->all()));
                foreach ($group as $l) {
                    $share = D::isPositive($total) ? D::div(D::mul($available, $needs[$l->id]['forecast'], D::QTY_SCALE), $total, 0, \App\Support\Enums\RoundingMode::TRUNCATE) : '0';
                    $out[$l->id] = $cap($l, D::exact($share));
                }
                break;
            case AllocationPolicy::FILL_TO_MAX:
            case AllocationPolicy::PRIORITY_ORDER:
                $remaining = $available;
                foreach ($group as $l) {   // already sorted by priority, then line
                    $q = $cap($l, D::min($needs[$l->id]['to_max'], $remaining));
                    $q = D::isNegative($q) ? '0' : $q;
                    $out[$l->id] = D::exact($q);
                    $remaining = D::sub($remaining, $q);
                }
                break;
        }

        return $out;
    }

    private function sourceAvailable(string $warehouseId, string $itemId, ?string $variantId): string
    {
        return D::exact((string) DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->where('il.location_type', InventoryLocationType::PHYSICAL->value)->where('b.warehouse_id', $warehouseId)->where('b.item_id', $itemId)
            ->where('b.stock_condition', StockCondition::AVAILABLE->value)
            ->when($variantId !== null, fn ($q) => $q->where('b.item_variant_id', $variantId))
            ->selectRaw('COALESCE(SUM(b.quantity_on_hand - b.quantity_reserved), 0) AS q')->value('q'));
    }

    private function assertWithinAvailability(AllocationPlan $plan): void
    {
        $totals = AllocationPlanLine::query()->where('allocation_plan_id', $plan->id)->groupBy('item_id', 'item_variant_id')
            ->selectRaw('item_id, item_variant_id, SUM(quantity) AS q')->get();
        foreach ($totals as $t) {
            $available = $this->sourceAvailable($plan->warehouse_id, $t->item_id, $t->item_variant_id);
            if (D::gt((string) $t->q, $available)) {
                $item = $this->lines->item($t->item_id, false);
                throw DomainException::insufficientStock($item->label(), D::trim((string) $t->q), D::trim($available), ['warehouse_id' => $plan->warehouse_id]);
            }
        }
    }
}
