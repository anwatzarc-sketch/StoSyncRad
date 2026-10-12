<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Fulfillment\Enums\PickLineStatus;
use App\Domains\Fulfillment\Enums\PickListStatus as S;
use App\Domains\Fulfillment\Enums\PickSourceType;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\Fulfillment\Models\PickList;
use App\Domains\Fulfillment\Models\PickListLine;
use App\Domains\Fulfillment\Models\PickWave;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\StockRequest;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Services\PickingPlanner;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Domains\Transfers\Models\TransferOrder;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pick lists and waves (Chapter 08 §8.6, §8.9). A pick list is guidance: it suggests positions —
 * first where the document already holds reservations, then by removal strategy (FEFO/FIFO/…) —
 * ordered along the bin path. The posting document (issue, delivery, pack, dispatch) names what was
 * actually picked; `pickedPayload()` turns a completed list into that payload.
 */
final class PickListService
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly PickingPlanner $planner,
        private readonly PlatformConfig $config,
    ) {}

    /** Transfer picking at the source warehouse (`transfers.transfer.pick`). */
    public function generateForTransfer(string $id, UserAccount $actor): PickList
    {
        return $this->generate(PickSourceType::TRANSFER_ORDER, $id, $actor);
    }

    public function generate(PickSourceType $type, string $sourceId, UserAccount $actor, ?string $waveId = null): PickList
    {
        return DB::transaction(function () use ($type, $sourceId, $actor, $waveId) {
            [$warehouseId, $outstanding, $reservationType] = $this->source($type, $sourceId);
            $this->authorizePicking($actor, $type, 'fulfillment.pick_list.assign', $warehouseId);
            $open = PickList::query()->where('source_type', $type->value)->where('source_id', $sourceId)
                ->whereIn('status', [S::OPEN->value, S::IN_PROGRESS->value])->exists();
            if ($open) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'An open pick list already exists for this document.');
            }
            $suggestions = [];
            foreach ($outstanding as $line) {
                $remaining = $line['quantity'];
                if ($reservationType !== null) {
                    $reserved = DB::table('stock_reservations as r')->join('inventory_locations as il', 'il.id', '=', 'r.inventory_location_id')
                        ->where('r.reference_type', $reservationType->value)->where('r.reference_line_id', $line['line_id'])
                        ->whereIn('r.status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])
                        ->orderBy('r.id')->get(['r.id', 'il.storage_location_id', 'r.inventory_lot_id', 'r.item_serial_id',
                            DB::raw('r.quantity_reserved - r.quantity_consumed - r.quantity_released AS outstanding')]);
                    foreach ($reserved as $r) {
                        if (! D::isPositive($remaining) || $r->storage_location_id === null) {
                            continue;
                        }
                        $take = D::min($remaining, D::exact((string) $r->outstanding));
                        $suggestions[] = $line + ['storage_location_id' => $r->storage_location_id, 'lot_id' => \App\Support\Sentinel::toNull($r->inventory_lot_id),
                            'serial_id' => $r->item_serial_id, 'reservation_id' => $r->id, 'take' => $take];
                        $remaining = D::sub($remaining, $take);
                    }
                }
                if (D::isPositive($remaining)) {
                    foreach ($this->planner->plan($this->lines->item($line['item_id'], false), $line['variant_id'], $warehouseId, $remaining) as $p) {
                        $suggestions[] = $line + ['storage_location_id' => $p['storage_location_id'], 'lot_id' => $p['lot_id'], 'serial_id' => null,
                            'reservation_id' => null, 'take' => $p['take']];
                    }
                }
            }
            if ($suggestions === []) {
                throw DomainException::of(ErrorCode::INSUFFICIENT_STOCK, 'Nothing can be picked for this document right now.');
            }
            $paths = DB::table('storage_locations')->whereIn('id', array_unique(array_column($suggestions, 'storage_location_id')))
                ->get(['id', 'sort_order', 'full_path'])->keyBy('id');
            usort($suggestions, fn ($a, $b) => [$paths[$a['storage_location_id']]->sort_order, $paths[$a['storage_location_id']]->full_path]
                <=> [$paths[$b['storage_location_id']]->sort_order, $paths[$b['storage_location_id']]->full_path]);

            $list = PickList::query()->forceCreate([
                'document_number' => $this->nextNumber(DocumentType::PICK_LIST, $warehouseId), 'warehouse_id' => $warehouseId, 'pick_wave_id' => $waveId,
                'source_type' => $type, 'source_id' => $sourceId, 'status' => S::OPEN, 'created_by' => $actor->id,
            ]);
            foreach ($suggestions as $n => $s) {
                PickListLine::query()->forceCreate([
                    'pick_list_id' => $list->id, 'line_no' => $n + 1, 'source_line_id' => $s['line_id'], 'item_id' => $s['item_id'], 'item_variant_id' => $s['variant_id'],
                    'storage_location_id' => $s['storage_location_id'], 'inventory_lot_id' => $s['lot_id'], 'item_serial_id' => $s['serial_id'],
                    'stock_reservation_id' => $s['reservation_id'], 'suggested_base_quantity' => $s['take'], 'status' => PickLineStatus::PENDING, 'sort_order' => $n + 1,
                ]);
            }
            $this->markSourcePicking($type, $sourceId);
            $this->audit($actor, 'fulfillment.pick_list.created', $list, null, ['document_number' => $list->document_number, 'lines' => count($suggestions)]);

            return $list->refresh();
        });
    }

    /**
     * Group confirmed e-commerce orders of a warehouse into one wave (`fulfillment.ecommerce.pick_wave_size`).
     *
     * @param  array<int, string>|null  $salesOrderIds  null = oldest eligible orders
     */
    public function wave(string $warehouseId, UserAccount $actor, ?array $salesOrderIds = null): PickWave
    {
        return DB::transaction(function () use ($warehouseId, $actor, $salesOrderIds) {
            $this->authorizeWarehouse($actor, 'fulfillment.pick_wave.manage', $warehouseId);
            $size = $this->config->int('fulfillment.ecommerce.pick_wave_size', ['warehouse' => $warehouseId]);
            $ids = SalesOrder::query()->where('warehouse_id', $warehouseId)->where('channel', 'ecommerce')
                ->whereIn('status', [SalesOrderStatus::CONFIRMED->value, SalesOrderStatus::RESERVED->value])
                ->when($salesOrderIds !== null, fn ($q) => $q->whereIn('id', $salesOrderIds))
                ->whereNotExists(fn ($q) => $q->from('pick_lists')->whereColumn('pick_lists.source_id', 'sales_orders.id')
                    ->whereIn('pick_lists.status', [S::OPEN->value, S::IN_PROGRESS->value]))
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")->orderBy('created_at')
                ->limit($size)->pluck('id');
            if ($ids->isEmpty()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'No confirmed orders are waiting to be picked.');
            }
            $wave = PickWave::query()->forceCreate(['document_number' => $this->nextNumber(DocumentType::PICK_LIST, $warehouseId).'-W',
                'warehouse_id' => $warehouseId, 'status' => S::OPEN, 'created_by' => $actor->id]);
            foreach ($ids as $orderId) {
                $this->generate(PickSourceType::SALES_ORDER, $orderId, $actor, $wave->id);
            }
            $this->audit($actor, 'fulfillment.pick_wave.created', $wave, null, ['orders' => $ids->count()]);

            return $wave;
        });
    }

    /**
     * Record picks: [{line_id, picked_quantity, short_pick_reason?}].
     *
     * @param  array<int, array<string, mixed>>  $picks
     */
    public function recordPicks(string $id, UserAccount $actor, array $picks): PickList
    {
        return DB::transaction(function () use ($id, $actor, $picks) {
            $list = $this->lockDocument(PickList::class, $id);
            $this->authorizePicking($actor, $list->source_type, 'fulfillment.pick_list.pick', $list->warehouse_id);
            $this->assertStatus($list, [S::OPEN, S::IN_PROGRESS]);
            $lines = PickListLine::query()->where('pick_list_id', $list->id)->lockForUpdate()->get()->keyBy('id');
            foreach ($picks as $i => $p) {
                $line = $lines->get($p['line_id'] ?? '') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown pick line.', ['field' => "lines.$i.line_id"]);
                $qty = D::exact($p['picked_quantity']);
                if (D::isNegative($qty) || D::gt($qty, $line->suggested_base_quantity)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Picked quantity must be between 0 and the suggested quantity.', ['field' => "lines.$i.picked_quantity"]);
                }
                $short = D::lt($qty, $line->suggested_base_quantity);
                if ($short && empty($p['short_pick_reason'])) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A short pick needs a reason.', ['field' => "lines.$i.short_pick_reason"]);
                }
                $line->forceFill(['picked_base_quantity' => $qty, 'status' => $short ? (D::isZero($qty) ? PickLineStatus::SKIPPED : PickLineStatus::SHORT_PICKED) : PickLineStatus::PICKED,
                    'short_pick_reason' => $short ? $p['short_pick_reason'] : null])->save();
            }
            if ($list->status === S::OPEN) {
                $this->transition($list, S::IN_PROGRESS, ['assigned_to' => $list->assigned_to ?? $actor->id]);
            }

            return $list;
        });
    }

    public function complete(string $id, UserAccount $actor): PickList
    {
        return DB::transaction(function () use ($id, $actor) {
            $list = $this->lockDocument(PickList::class, $id);
            $this->authorizePicking($actor, $list->source_type, 'fulfillment.pick_list.complete', $list->warehouse_id);
            if (PickListLine::query()->where('pick_list_id', $list->id)->where('status', PickLineStatus::PENDING->value)->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Every line must be picked, short-picked or skipped first.');
            }
            $this->transition($list, S::COMPLETED, ['completed_by' => $actor->id, 'completed_at' => now()]);
            $this->audit($actor, 'fulfillment.pick_list.completed', $list);

            return $list;
        });
    }

    /**
     * Picked lines in the shape of the posting payloads (issue lines / PickedLinesSchema).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pickedPayload(string $id): array
    {
        $serials = DB::table('item_serials')->pluck('serial_number', 'id');
        $out = [];
        foreach (PickListLine::query()->where('pick_list_id', $id)->where('picked_base_quantity', '>', 0)->orderBy('line_no')->get() as $l) {
            $key = implode('|', [$l->source_line_id, $l->storage_location_id, $l->inventory_lot_id]);
            $out[$key] ??= ['order_line_id' => $l->source_line_id, 'source_line_id' => $l->source_line_id, 'item_id' => $l->item_id,
                'item_variant_id' => $l->item_variant_id, 'storage_location_id' => $l->storage_location_id, 'inventory_lot_id' => $l->inventory_lot_id,
                'serial_numbers' => [], 'quantity' => '0'];
            $out[$key]['quantity'] = D::add($out[$key]['quantity'], $l->picked_base_quantity);
            if ($l->item_serial_id !== null) {
                $out[$key]['serial_numbers'][] = $serials[$l->item_serial_id] ?? null;
            }
        }

        return array_values($out);
    }

    /**
     * @return array{0: string, 1: array<int, array{line_id: string, item_id: string, variant_id: ?string, quantity: string}>, 2: ?ReservationReferenceType}
     */
    private function source(PickSourceType $type, string $id): array
    {
        switch ($type) {
            case PickSourceType::SALES_ORDER:
                $order = SalesOrder::query()->findOrFail($id);
                if (! in_array($order->status, [SalesOrderStatus::CONFIRMED, SalesOrderStatus::RESERVED, SalesOrderStatus::PICKING, SalesOrderStatus::PARTIALLY_DELIVERED], true)) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Order {$order->document_number} is not ready for picking.");
                }
                $lines = DB::table('sales_order_lines')->where('sales_order_id', $id)->orderBy('line_no')
                    ->get(['id', 'item_id', 'item_variant_id', DB::raw('base_quantity - delivered_base_quantity AS q')]);

                return [$order->warehouse_id, $this->outstanding($lines), ReservationReferenceType::SALES_ORDER];
            case PickSourceType::STOCK_REQUEST:
                $req = StockRequest::query()->findOrFail($id);
                if (! in_array($req->status, [StockRequestStatus::APPROVED, StockRequestStatus::PARTIALLY_FULFILLED], true)) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Request {$req->document_number} is not approved.");
                }
                $lines = DB::table('stock_request_lines')->where('stock_request_id', $id)->orderBy('line_no')
                    ->get(['id', 'item_id', 'item_variant_id', DB::raw('approved_base_quantity - fulfilled_base_quantity AS q')]);

                return [$req->warehouse_id, $this->outstanding($lines), ReservationReferenceType::STOCK_REQUEST];
            case PickSourceType::TRANSFER_ORDER:
                $to = TransferOrder::query()->findOrFail($id);
                if (! in_array($to->status, [TransferStatus::APPROVED, TransferStatus::PICKING, TransferStatus::PARTIALLY_DISPATCHED], true)) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Transfer {$to->document_number} is not ready for picking.");
                }
                $lines = DB::table('transfer_order_lines')->where('transfer_order_id', $id)->orderBy('line_no')
                    ->get(['id', 'item_id', 'item_variant_id', DB::raw('approved_base_quantity - dispatched_base_quantity - cancelled_base_quantity AS q')]);

                return [$to->source_warehouse_id, $this->outstanding($lines), ReservationReferenceType::TRANSFER_ORDER];
            default:
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Pick lists for this source are generated from its request.');
        }
    }

    /** @return array<int, array{line_id: string, item_id: string, variant_id: ?string, quantity: string}> */
    private function outstanding(Collection $lines): array
    {
        return $lines->filter(fn ($l) => D::isPositive((string) $l->q))
            ->map(fn ($l) => ['line_id' => $l->id, 'item_id' => $l->item_id, 'variant_id' => $l->item_variant_id, 'quantity' => D::exact((string) $l->q)])
            ->values()->all();
    }

    private function markSourcePicking(PickSourceType $type, string $id): void
    {
        if ($type === PickSourceType::SALES_ORDER) {
            $order = SalesOrder::query()->lockForUpdate()->find($id);
            if (in_array($order->status, [SalesOrderStatus::CONFIRMED, SalesOrderStatus::RESERVED], true)) {
                $this->transition($order, SalesOrderStatus::PICKING);
            }
        } elseif ($type === PickSourceType::TRANSFER_ORDER) {
            $to = TransferOrder::query()->lockForUpdate()->find($id);
            if ($to->status === TransferStatus::APPROVED) {
                $this->transition($to, TransferStatus::PICKING);
            }
        }
    }

    /** Transfer pick lists are worked by holders of `transfers.transfer.pick`; other sources by the fulfillment permission. */
    private function authorizePicking(UserAccount $actor, PickSourceType $type, string $permission, string $warehouseId): void
    {
        if ($type === PickSourceType::TRANSFER_ORDER) {
            $scope = ['warehouse_id' => $warehouseId, 'branch_id' => \App\Domains\Organization\Models\Warehouse::query()->whereKey($warehouseId)->value('branch_id')];
            if (app(\App\Domains\IdentityAccess\Services\Access::class)->can($actor, 'transfers.transfer.pick', $scope)) {
                return;
            }
        }
        $this->authorizeWarehouse($actor, $permission, $warehouseId);
    }
}
