<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Services;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationPolicy;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Domains\Transfers\Enums\BatchVerifyStrictness;
use App\Domains\Transfers\Enums\DiscrepancyResolution as R;
use App\Domains\Transfers\Enums\DiscrepancyStatus;
use App\Domains\Transfers\Enums\DiscrepancyType;
use App\Domains\Transfers\Enums\TransferEventStatus;
use App\Domains\Transfers\Enums\TransferSide;
use App\Domains\Transfers\Enums\TransferStatus as S;
use App\Domains\Transfers\Models\TransferDiscrepancy;
use App\Domains\Transfers\Models\TransferDiscrepancyResolution;
use App\Domains\Transfers\Models\TransferDispatch;
use App\Domains\Transfers\Models\TransferDispatchLine;
use App\Domains\Transfers\Models\TransferOrder;
use App\Domains\Transfers\Models\TransferOrderLine;
use App\Domains\Transfers\Models\TransferReceipt;
use App\Domains\Transfers\Models\TransferReceiptLine;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inter-branch transfers (Chapter 08 §8.4).
 *
 * Conservation per line: dispatched = received + remaining in transit + resolved. Stock in transit
 * is locked at position level (InventoryPoster) and only leaves through a receipt or an authorized
 * discrepancy resolution. A receipt verifies the dispatched lot/serial before stock increases at
 * the destination, and a discrepancy never alters the dispatch record.
 */
final class TransferWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly ReservationService $reservations,
        private readonly Access $access,
    ) {}

    // ---------------------------------------------------------------- drafting & approval ----

    /** @param  array<string, mixed>  $data  validated against TransferOrderSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): TransferOrder
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $source = Warehouse::query()->findOrFail($data['source_warehouse_id']);
                $destination = Warehouse::query()->findOrFail($data['destination_warehouse_id']);
                if ($source->id === $destination->id && ! $this->config->bool('transfers.allow_same_warehouse_relocation')) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Source and destination must be different warehouses.', ['field' => 'destination_warehouse_id']);
                }
                $this->authorizeSide($actor, 'transfers.transfer.create', TransferSide::from((string) $this->config->get('transfers.create_side')), $source->id, $destination->id);
                $to = new TransferOrder;
                $to->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::TRANSFER_ORDER, $source->id),
                    'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id, 'branch_id' => $source->branch_id,
                    'status' => S::DRAFT, 'requested_by' => $actor->id, 'created_by' => $actor->id,
                    'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $to = $this->lockDocument(TransferOrder::class, $id);
                $this->authorizeEitherWarehouse($actor, 'transfers.transfer.update', $to->source_warehouse_id, $to->destination_warehouse_id);
                $this->expectVersion($to, $data['version'] ?? null);
                $this->assertStatus($to, [S::DRAFT]);
                $to->forceFill(['version' => $to->version + 1]);
            }
            $to->fill(array_intersect_key($data, array_flip(['document_date', 'required_by', 'reason', 'priority', 'carrier_partner_id', 'reference', 'notes'])));
            $to->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                TransferOrderLine::query()->where('transfer_order_id', $to->id)->delete();
                foreach (array_values($data['lines']) as $i => $line) {
                    $item = $this->lines->item($line['item_id']);
                    if (! $item->is_transferable) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is not transferable.", ['field' => "lines.$i.item_id"]);
                    }
                    [$uomId, $base, $factor] = $this->lines->toBase($line['item_id'], $line['uom_id'] ?? $item->transfer_uom_id, $line['quantity']);
                    TransferOrderLine::query()->create([
                        'transfer_order_id' => $to->id, 'line_no' => $i + 1, 'item_id' => $item->id, 'item_variant_id' => $line['item_variant_id'] ?? null,
                        'inventory_lot_id' => $line['inventory_lot_id'] ?? null, 'uom_id' => $uomId, 'conversion_factor_snapshot' => $factor,
                        'requested_quantity' => $line['quantity'], 'requested_base_quantity' => $base, 'notes' => $line['notes'] ?? null,
                    ]);
                }
            }
            $this->audit($actor, $id === null ? 'transfers.transfer.created' : 'transfers.transfer.updated', $to, null, ['document_number' => $to->document_number]);

            return $to->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): TransferOrder
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $to = $this->lockDocument(TransferOrder::class, $id);
            $this->authorizeEitherWarehouse($actor, 'transfers.transfer.submit', $to->source_warehouse_id, $to->destination_warehouse_id);
            $this->expectVersion($to, $version);
            $this->transition($to, S::SUBMITTED, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::TRANSFER_ORDER, $to->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'transfers.transfer.submitted', $to);
            $this->publish('transfers.transfer.submitted', $to, ['document_number' => $to->document_number, 'source_warehouse_id' => $to->source_warehouse_id]);

            return $to;
        });
    }

    /**
     * @param  array<int, array{transfer_order_line_id: string, approved_quantity: string}>|null  $approvedLines
     */
    public function approve(string $id, UserAccount $actor, ?array $approvedLines = null, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): TransferOrder
    {
        return DB::transaction(function () use ($id, $actor, $approvedLines, $version, $comment, $overrideReason) {
            $to = $this->lockDocument(TransferOrder::class, $id);
            $this->expectVersion($to, $version);
            $this->assertStatus($to, [S::SUBMITTED]);
            $side = TransferSide::from((string) $this->config->get('transfers.approve_side'));
            $this->authorizeSide($actor, 'transfers.transfer.approve', $side, $to->source_warehouse_id, $to->destination_warehouse_id);

            $lines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->orderBy('line_no')->lockForUpdate()->get()->keyBy('id');
            $approved = [];
            foreach ($lines as $line) {
                $approved[$line->id] = D::exact($line->requested_base_quantity);
            }
            foreach ((array) $approvedLines as $row) {
                $line = $lines->get($row['transfer_order_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown transfer line.');
                if (D::gt($row['approved_quantity'], $line->requested_base_quantity)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Approved quantity cannot exceed the requested quantity.', ['line' => $line->line_no]);
                }
                $approved[$line->id] = D::exact($row['approved_quantity']);
            }
            $value = $this->transferValue($lines, $approved);
            $this->enforceSod(DocumentType::TRANSFER_ORDER, DocumentAction::APPROVE, $this->documentActors($to, DocumentType::TRANSFER_ORDER), $actor, $to->id, $value, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::TRANSFER_ORDER, $to->id, $actor, $value, null,
                ['warehouse_id' => $side === TransferSide::DESTINATION ? $to->destination_warehouse_id : $to->source_warehouse_id], $comment);
            if (! $complete) {
                $to->forceFill(['version' => $to->version + 1])->save();

                return $to;
            }
            foreach ($lines as $line) {
                $line->forceFill(['approved_base_quantity' => $approved[$line->id]])->save();
            }
            $this->transition($to, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);

            $policy = ReservationPolicy::from((string) $this->config->get('stock.reservation.policy', ['warehouse' => $to->source_warehouse_id]));
            if ($policy === ReservationPolicy::ON_APPROVAL) {
                foreach ($lines as $line) {
                    if (D::isPositive($approved[$line->id])) {
                        $this->reservations->reserveFromWarehouse(ReservationReferenceType::TRANSFER_ORDER, $to->id, $line->id, $this->lines->item($line->item_id, false),
                            $line->item_variant_id, $to->source_warehouse_id, $approved[$line->id], $actor, allowPartial: true, lotId: $line->inventory_lot_id);
                    }
                }
            }
            $this->audit($actor, 'transfers.transfer.approved', $to, null, ['value' => $value], $comment);
            $this->publish('transfers.transfer.approved', $to, ['document_number' => $to->document_number, 'source_warehouse_id' => $to->source_warehouse_id]);

            return $to;
        });
    }

    /**
     * Transfer generated by an approved allocation plan (Chapter 08 §8.1): the plan approval is the
     * transfer approval, so the order is created directly in `approved` and reserved per policy.
     *
     * @param  array<string, mixed>  $data  as for save()
     */
    public function createApproved(array $data, UserAccount $actor, string $allocationPlanId, string $approvedBy): TransferOrder
    {
        return DB::transaction(function () use ($data, $actor, $allocationPlanId, $approvedBy) {
            $to = $this->lockDocument(TransferOrder::class, $this->save($data, $actor)->id);
            $lines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->orderBy('line_no')->get();
            foreach ($lines as $line) {
                $line->forceFill(['approved_base_quantity' => $line->requested_base_quantity])->save();
            }
            $to->forceFill(['allocation_plan_id' => $allocationPlanId, 'status' => S::APPROVED, 'submitted_by' => $actor->id, 'submitted_at' => now(),
                'approved_by' => $approvedBy, 'approved_at' => now(), 'version' => $to->version + 1])->save();
            if (ReservationPolicy::from((string) $this->config->get('stock.reservation.policy', ['warehouse' => $to->source_warehouse_id])) === ReservationPolicy::ON_APPROVAL) {
                foreach ($lines as $line) {
                    $this->reservations->reserveFromWarehouse(ReservationReferenceType::TRANSFER_ORDER, $to->id, $line->id, $this->lines->item($line->item_id, false),
                        $line->item_variant_id, $to->source_warehouse_id, D::exact($line->requested_base_quantity), $actor, allowPartial: true, lotId: $line->inventory_lot_id);
                }
            }
            $this->audit($actor, 'transfers.transfer.approved', $to, null, ['allocation_plan_id' => $allocationPlanId]);
            $this->publish('transfers.transfer.approved', $to, ['document_number' => $to->document_number, 'source_warehouse_id' => $to->source_warehouse_id,
                'allocation_plan_id' => $allocationPlanId]);

            return $to;
        });
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): TransferOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $to = $this->lockDocument(TransferOrder::class, $id);
            $this->expectVersion($to, $version);
            $this->assertStatus($to, [S::SUBMITTED]);
            $this->authorizeSide($actor, 'transfers.transfer.approve', TransferSide::from((string) $this->config->get('transfers.approve_side')), $to->source_warehouse_id, $to->destination_warehouse_id);
            $this->transition($to, S::DRAFT);
            $this->approvals->record(DocumentType::TRANSFER_ORDER, $to->id, $actor, ApprovalAction::REJECTED, $reason);
            $this->audit($actor, 'transfers.transfer.rejected', $to, null, null, $reason);

            return $to;
        });
    }

    /** Cancel before dispatch; after a dispatch only the undispatched remainder can be cancelled. */
    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): TransferOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $to = $this->lockDocument(TransferOrder::class, $id);
            $this->authorizeEitherWarehouse($actor, 'transfers.transfer.cancel', $to->source_warehouse_id, $to->destination_warehouse_id);
            $this->expectVersion($to, $version);
            $lines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->lockForUpdate()->get();
            $dispatched = D::sum($lines->pluck('dispatched_base_quantity'));
            $this->reservations->releaseForReference(ReservationReferenceType::TRANSFER_ORDER, $to->id, $actor, 'Transfer cancelled: '.($reason ?? ''));
            if (D::isPositive($dispatched)) {
                foreach ($lines as $line) {
                    $open = D::sub(D::sub($line->approved_base_quantity, $line->dispatched_base_quantity), $line->cancelled_base_quantity);
                    if (D::isPositive($open)) {
                        $line->forceFill(['cancelled_base_quantity' => D::add($line->cancelled_base_quantity, $open)])->save();
                    }
                }
                $this->deriveStatus($to);
                $this->audit($actor, 'transfers.transfer.remainder_cancelled', $to, null, null, $reason);

                return $to->refresh();
            }
            $this->transition($to, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'transfers.transfer.cancelled', $to, null, null, $reason);
            $this->publish('transfers.transfer.cancelled', $to, ['document_number' => $to->document_number]);

            return $to;
        });
    }

    // ----------------------------------------------------------------------- dispatch ----

    /** @param  array<string, mixed>  $data  validated against TransferDispatchSchema */
    public function dispatch(string $id, UserAccount $actor, ?string $idempotencyKey, array $data): PostingOutcome
    {
        return $this->pipeline->run($actor, 'transfers.transfer.dispatch', $idempotencyKey, ['transfer_order_id' => $id] + $data,
            function (PostingContext $ctx) use ($id, $actor, $data) {
                $to = $this->lockDocument(TransferOrder::class, $id);
                $this->authorizeWarehouse($actor, 'transfers.transfer.dispatch', $to->source_warehouse_id);
                $this->expectVersion($to, $data['version'] ?? null);
                $this->assertStatus($to, [S::APPROVED, S::PICKING, S::PARTIALLY_DISPATCHED, S::PARTIALLY_RECEIVED]);
                $this->enforceSod(DocumentType::TRANSFER_ORDER, DocumentAction::DISPATCH, $this->documentActors($to, DocumentType::TRANSFER_ORDER),
                    $actor, $to->id, null, null, $data['override_reason'] ?? null);

                $source = Warehouse::query()->findOrFail($to->source_warehouse_id);
                $destination = Warehouse::query()->findOrFail($to->destination_warehouse_id);
                $source->assertOperational();
                $source->assertCapability(WarehouseCapability::DISPATCH_TRANSFERS);
                $destination->assertCapability(WarehouseCapability::RECEIVE_TRANSFERS);
                if ($this->config->bool('transfers.require_carrier') && empty($data['carrier_partner_id']) && $to->carrier_partner_id === null) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A carrier is required for dispatches.', ['field' => 'carrier_partner_id']);
                }

                $orderLines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $transit = $this->locations->inTransit($source->id, $destination->id)->id;
                $dispatchId = (string) Str::uuid7();
                $documentNumber = $this->nextNumber(DocumentType::TRANSFER_DISPATCH, $source->id);
                $movementLines = [];
                $dispatchRows = [];
                $perLine = [];

                foreach (array_values($data['lines']) as $i => $row) {
                    /** @var TransferOrderLine $line */
                    $line = $orderLines->get($row['transfer_order_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown transfer line.', ['field' => "lines.$i"]);
                    $item = $this->lines->item($line->item_id, false);
                    $storage = StorageLocation::query()->findOrFail($row['storage_location_id']);
                    if ($storage->warehouse_id !== $source->id) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Stock must be picked from the source warehouse.', ['field' => "lines.$i.storage_location_id"]);
                    }
                    $lotId = $row['inventory_lot_id'] ?? null;
                    if ($line->inventory_lot_id !== null && $lotId !== $line->inventory_lot_id) {
                        throw DomainException::of(ErrorCode::LOT_MISMATCH, 'This transfer line is pinned to a specific lot.', ['field' => "lines.$i.inventory_lot_id"]);
                    }
                    $qty = D::exact($row['quantity']);
                    $perLine[$line->id] = D::add($perLine[$line->id] ?? '0', $qty);
                    $fromLocation = $this->locations->forStorageLocation($storage->id)->id;

                    // Consume this line's reservation at the picked position, if any.
                    $consume = [];
                    $key = new PositionKey($item->id, $line->item_variant_id, $fromLocation, $lotId, StockCondition::AVAILABLE);
                    $reservation = StockReservation::query()->where('reference_type', ReservationReferenceType::TRANSFER_ORDER->value)->where('reference_line_id', $line->id)
                        ->where('inventory_location_id', $fromLocation)->where('inventory_lot_id', $key->lotId)
                        ->whereIn('status', ['active', 'partially_consumed'])->first();
                    if ($reservation !== null) {
                        $consume[$reservation->id] = D::min($qty, $reservation->outstanding());
                    }

                    if ($item->tracking_mode === TrackingMode::SERIAL) {
                        $serialNumbers = array_values(array_unique((array) ($row['serial_numbers'] ?? [])));
                        if (! D::isWhole($qty) || count($serialNumbers) !== (int) $qty) {
                            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'Scan one serial number per unit dispatched.', ['field' => "lines.$i.serial_numbers"]);
                        }
                        $remainingConsume = $consume;
                        foreach ($serialNumbers as $serialNumber) {
                            $serial = ItemSerial::query()->where('item_id', $item->id)->where('serial_number', $serialNumber)->first()
                                ?? throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Unknown serial $serialNumber.");
                            $lineConsume = [];
                            foreach ($remainingConsume as $rid => $left) {
                                if (D::isPositive($left)) {
                                    $lineConsume[$rid] = '1';
                                    $remainingConsume[$rid] = D::sub($left, '1');
                                    break;
                                }
                            }
                            $movementLines[] = new MovementLine($item->id, $line->item_variant_id, $fromLocation, StockCondition::AVAILABLE, $transit, StockCondition::AVAILABLE, '1',
                                lotId: $serial->inventory_lot_id ?? $lotId, serialId: $serial->id, sourceLineType: 'transfer_order_line', sourceLineId: $line->id, consumeReservations: $lineConsume);
                            $dispatchRows[] = [$line, $item, $serial->inventory_lot_id ?? $lotId, $serial->id, $storage->id, '1'];
                        }
                    } else {
                        $movementLines[] = new MovementLine($item->id, $line->item_variant_id, $fromLocation, StockCondition::AVAILABLE, $transit, StockCondition::AVAILABLE, $qty,
                            lotId: $lotId, sourceLineType: 'transfer_order_line', sourceLineId: $line->id, consumeReservations: $consume);
                        $dispatchRows[] = [$line, $item, $lotId, null, $storage->id, $qty];
                    }
                }
                foreach ($perLine as $lineId => $qty) {
                    $line = $orderLines[$lineId];
                    $open = D::sub(D::sub($line->approved_base_quantity, $line->dispatched_base_quantity), $line->cancelled_base_quantity);
                    if (D::gt($qty, $open)) {
                        throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$line->line_no} would dispatch more than approved.",
                            ['line' => $line->line_no, 'outstanding' => $open, 'requested' => $qty]);
                    }
                }

                $movement = $this->poster->post(new MovementRequest(MovementType::TRANSFER_DISPATCH, DocumentType::TRANSFER_DISPATCH, $dispatchId, $documentNumber, $dispatchId,
                    $movementLines, warehouseId: $source->id, counterpartyWarehouseId: $destination->id), $ctx);
                $costs = DB::table('stock_movement_lines')->where('stock_movement_id', $movement->id)->orderBy('line_no')->pluck('unit_cost_snapshot')->all();

                TransferDispatch::query()->forceCreate([
                    'id' => $dispatchId, 'document_number' => $documentNumber, 'transfer_order_id' => $to->id, 'status' => TransferEventStatus::POSTED,
                    'dispatched_by' => $actor->id, 'dispatched_at' => now(), 'carrier_partner_id' => $data['carrier_partner_id'] ?? $to->carrier_partner_id,
                    'tracking_reference' => $data['tracking_reference'] ?? null, 'seal_reference' => $data['seal_reference'] ?? null,
                    'vehicle_reference' => $data['vehicle_reference'] ?? null, 'expected_arrival' => $data['expected_arrival'] ?? null,
                    'stock_movement_id' => $movement->id, 'notes' => $data['notes'] ?? null,
                ]);
                foreach ($dispatchRows as $n => [$line, $item, $lotId, $serialId, $storageId, $qty]) {
                    TransferDispatchLine::query()->create([
                        'transfer_dispatch_id' => $dispatchId, 'transfer_order_line_id' => $line->id, 'item_id' => $item->id, 'item_variant_id' => $line->item_variant_id,
                        'inventory_lot_id' => $lotId, 'item_serial_id' => $serialId, 'source_storage_location_id' => $storageId, 'base_quantity' => $qty,
                        'unit_cost_snapshot' => $costs[$n] ?? '0',
                    ]);
                }
                foreach ($perLine as $lineId => $qty) {
                    $orderLines[$lineId]->forceFill(['dispatched_base_quantity' => D::add($orderLines[$lineId]->dispatched_base_quantity, $qty)])->save();
                }
                $this->deriveStatus($to);

                $ctx->audit('transfers.transfer.dispatched', 'transfer_orders', $to->id, null,
                    ['dispatch_number' => $documentNumber, 'movement_number' => $movement->movement_number], $data['override_reason'] ?? null);
                $ctx->publish('transfers.transfer.dispatched', 'transfer_orders', $to->id,
                    ['document_number' => $to->document_number, 'dispatch_number' => $documentNumber, 'destination_warehouse_id' => $destination->id]);

                return new PostingOutcome(['transfer_order_id' => $to->id, 'dispatch_id' => $dispatchId, 'dispatch_number' => $documentNumber,
                    'status' => $to->refresh()->status->value, 'stock_movement_id' => $movement->id], 201, DocumentType::TRANSFER_DISPATCH->value, $dispatchId);
            }, failureAudit: ['transfers.transfer.dispatch_failed', 'transfer_orders', $id]);
    }

    // ------------------------------------------------------------------------ receipt ----

    /** @param  array<string, mixed>  $data  validated against TransferReceiptSchema */
    public function receive(string $id, UserAccount $actor, ?string $idempotencyKey, array $data): PostingOutcome
    {
        return $this->pipeline->run($actor, 'transfers.transfer.receive', $idempotencyKey, ['transfer_order_id' => $id] + $data,
            function (PostingContext $ctx) use ($id, $actor, $data) {
                $to = $this->lockDocument(TransferOrder::class, $id);
                $this->authorizeWarehouse($actor, 'transfers.transfer.receive', $to->destination_warehouse_id);
                $this->expectVersion($to, $data['version'] ?? null);
                $this->assertStatus($to, [S::PARTIALLY_DISPATCHED, S::DISPATCHED, S::IN_TRANSIT, S::PARTIALLY_RECEIVED, S::DISCREPANCY_REVIEW]);
                $actors = $this->documentActors($to, DocumentType::TRANSFER_ORDER);
                $actors[DocumentAction::DISPATCH->value] = TransferDispatch::query()->where('transfer_order_id', $to->id)->pluck('dispatched_by')->filter()->values()->all();
                $this->enforceSod(DocumentType::TRANSFER_ORDER, DocumentAction::RECEIVE, $actors, $actor, $to->id, null, null, $data['override_reason'] ?? null);

                $destination = Warehouse::query()->findOrFail($to->destination_warehouse_id);
                $destination->assertOperational(inbound: true);
                $transit = $this->locations->inTransit($to->source_warehouse_id, $destination->id)->id;
                $strictness = BatchVerifyStrictness::from((string) $this->config->get('transfers.batch_verify.strictness'));

                $dispatchLineIds = array_column($data['lines'], 'transfer_dispatch_line_id');
                $dispatchLines = TransferDispatchLine::query()->whereIn('id', $dispatchLineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $orderLines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $receiptId = (string) Str::uuid7();
                $documentNumber = $this->nextNumber(DocumentType::TRANSFER_RECEIPT, $destination->id);
                $movementLines = [];
                $discrepancies = [];
                $receiptRows = [];

                foreach (array_values($data['lines']) as $i => $row) {
                    /** @var TransferDispatchLine $dl */
                    $dl = $dispatchLines->get($row['transfer_dispatch_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown dispatch line.', ['field' => "lines.$i"]);
                    $orderLine = $orderLines->get($dl->transfer_order_line_id) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The dispatch line belongs to another transfer.');
                    $this->verifyBatch($dl, $row, $strictness, $i);

                    $accepted = D::exact($row['accepted_quantity'] ?? '0');
                    $damaged = D::exact($row['damaged_quantity'] ?? '0');
                    if (($row['receiving_condition'] ?? null) === ReceivingCondition::DAMAGED->value && D::isPositive($accepted)) {
                        [$accepted, $damaged] = ['0.000000', D::add($accepted, $damaged)];
                    }
                    $outstanding = D::sub(D::sub($dl->base_quantity, $dl->received_base_quantity), $dl->resolved_base_quantity);
                    $moved = D::add($accepted, $damaged);
                    if (D::gt($moved, $outstanding)) {
                        throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, 'More was received than is outstanding for this dispatch line; record the excess separately.',
                            ['field' => "lines.$i", 'outstanding' => $outstanding]);
                    }
                    $quarantine = ($row['receiving_condition'] ?? null) === ReceivingCondition::QUARANTINE->value;
                    if (D::isPositive($accepted)) {
                        $storage = $this->destinationLocation($destination, $row['destination_storage_location_id'] ?? null, $quarantine ? StorageLocationUsage::QUARANTINE : StorageLocationUsage::RECEIVING, $i);
                        $movementLines[] = new MovementLine($dl->item_id, $dl->item_variant_id, $transit, StockCondition::AVAILABLE,
                            $this->locations->forStorageLocation($storage->id)->id, $quarantine ? StockCondition::QUARANTINE : StockCondition::AVAILABLE, $accepted,
                            lotId: $dl->inventory_lot_id, serialId: $dl->item_serial_id, sourceLineType: 'transfer_dispatch_line', sourceLineId: $dl->id);
                    }
                    if (D::isPositive($damaged)) {
                        $damagedStorage = $this->destinationLocation($destination, null, StorageLocationUsage::DAMAGED, $i);
                        $movementLines[] = new MovementLine($dl->item_id, $dl->item_variant_id, $transit, StockCondition::AVAILABLE,
                            $this->locations->forStorageLocation($damagedStorage->id)->id, StockCondition::DAMAGED, $damaged,
                            lotId: $dl->inventory_lot_id, serialId: $dl->item_serial_id, sourceLineType: 'transfer_dispatch_line', sourceLineId: $dl->id);
                        $discrepancies[] = [DiscrepancyType::DAMAGE, $dl, $damaged, DiscrepancyStatus::RESOLVED, $row];
                    }
                    $shortage = '0';
                    if (($row['report_shortage'] ?? false) === true) {
                        $shortage = D::sub($outstanding, $moved);
                        if (D::isPositive($shortage)) {
                            $discrepancies[] = [DiscrepancyType::SHORTAGE, $dl, $shortage, DiscrepancyStatus::OPEN, $row];
                        }
                    }
                    if (D::isPositive($row['excess_quantity'] ?? '0')) {
                        $discrepancies[] = [DiscrepancyType::EXCESS, $dl, D::exact($row['excess_quantity']), DiscrepancyStatus::OPEN, $row];
                    }
                    $dl->forceFill(['received_base_quantity' => D::add($dl->received_base_quantity, $moved)])->save();
                    $orderLine->forceFill(['received_base_quantity' => D::add($orderLine->received_base_quantity, $moved)])->save();
                    $receiptRows[] = [$dl, $accepted, $damaged, $shortage, $row, $storage ?? null];
                }

                $movement = null;
                if ($movementLines !== []) {
                    $movement = $this->poster->post(new MovementRequest(MovementType::TRANSFER_RECEIPT, DocumentType::TRANSFER_RECEIPT, $receiptId, $documentNumber, $receiptId,
                        $movementLines, warehouseId: $destination->id, counterpartyWarehouseId: $to->source_warehouse_id), $ctx);
                }
                TransferReceipt::query()->forceCreate([
                    'id' => $receiptId, 'document_number' => $documentNumber, 'transfer_order_id' => $to->id, 'status' => TransferEventStatus::POSTED,
                    'received_by' => $actor->id, 'received_at' => now(), 'stock_movement_id' => $movement?->id, 'notes' => $data['notes'] ?? null,
                ]);
                $receiptLineIds = [];
                foreach ($receiptRows as [$dl, $accepted, $damaged, $shortage, $row, $storage]) {
                    $receiptLine = TransferReceiptLine::query()->create([
                        'transfer_receipt_id' => $receiptId, 'transfer_dispatch_line_id' => $dl->id, 'accepted_base_quantity' => $accepted,
                        'damaged_base_quantity' => $damaged, 'shortage_base_quantity' => $shortage, 'excess_base_quantity' => $row['excess_quantity'] ?? '0',
                        'receiving_condition' => $row['receiving_condition'] ?? ReceivingCondition::GOOD->value,
                        'destination_storage_location_id' => $storage?->id ?? $destination->default_receiving_location_id,
                        'scanned_lot_number' => $row['scanned_lot_number'] ?? null, 'scanned_serial_number' => $row['scanned_serial_number'] ?? null,
                        'discrepancy_reason_lookup_value_id' => $row['discrepancy_reason_lookup_value_id'] ?? null, 'notes' => $row['notes'] ?? null,
                    ]);
                    $receiptLineIds[$dl->id] = $receiptLine->id;
                }
                foreach ($discrepancies as $n => [$type, $dl, $qty, $status, $row]) {
                    TransferDiscrepancy::query()->create([
                        'document_number' => $documentNumber.'-D'.($n + 1),
                        'transfer_order_id' => $to->id, 'transfer_dispatch_line_id' => $dl->id, 'transfer_receipt_line_id' => $receiptLineIds[$dl->id] ?? null,
                        'item_id' => $dl->item_id, 'inventory_lot_id' => $dl->inventory_lot_id, 'item_serial_id' => $dl->item_serial_id,
                        'discrepancy_type' => $type, 'base_quantity' => $qty, 'resolved_base_quantity' => $status === DiscrepancyStatus::RESOLVED ? $qty : '0',
                        'status' => $status, 'reason_lookup_value_id' => $row['discrepancy_reason_lookup_value_id'] ?? null, 'notes' => $row['notes'] ?? null,
                        'reported_by' => $actor->id, 'version' => 1,
                    ]);
                }
                $this->deriveStatus($to);
                $ctx->audit('transfers.transfer.received', 'transfer_orders', $to->id, null,
                    ['receipt_number' => $documentNumber, 'movement_number' => $movement?->movement_number, 'discrepancies' => count($discrepancies)]);
                $ctx->publish('transfers.transfer.received', 'transfer_orders', $to->id, ['document_number' => $to->document_number, 'receipt_number' => $documentNumber]);
                if (collect($discrepancies)->contains(fn ($d) => $d[3] === DiscrepancyStatus::OPEN)) {
                    $ctx->publish('transfers.discrepancy.reported', 'transfer_orders', $to->id, ['document_number' => $to->document_number]);
                }

                return new PostingOutcome(['transfer_order_id' => $to->id, 'receipt_id' => $receiptId, 'receipt_number' => $documentNumber,
                    'status' => $to->refresh()->status->value, 'stock_movement_id' => $movement?->id], 201, DocumentType::TRANSFER_RECEIPT->value, $receiptId);
            }, failureAudit: ['transfers.transfer.receive_failed', 'transfer_orders', $id]);
    }

    // ---------------------------------------------------------------- discrepancies ----

    /** @param  array<string, mixed>  $data  validated against DiscrepancyResolutionSchema */
    public function resolveDiscrepancy(string $transferId, string $discrepancyId, UserAccount $actor, ?string $idempotencyKey, array $data): PostingOutcome
    {
        return $this->pipeline->run($actor, 'transfers.discrepancy.resolve', $idempotencyKey, ['discrepancy' => $discrepancyId] + $data,
            function (PostingContext $ctx) use ($transferId, $discrepancyId, $actor, $data) {
                $to = $this->lockDocument(TransferOrder::class, $transferId);
                /** @var TransferDiscrepancy $d */
                $d = $this->lockDocument(TransferDiscrepancy::class, $discrepancyId);
                if ($d->transfer_order_id !== $to->id) {
                    throw DomainException::notFound('Discrepancy');
                }
                $resolution = R::from($data['resolution']);
                $permission = $resolution === R::KEEP_IN_TRANSIT ? 'transfers.discrepancy.investigate' : 'transfers.discrepancy.resolve';
                $this->authorizeEitherWarehouse($actor, $permission, $to->source_warehouse_id, $to->destination_warehouse_id);
                $this->expectVersion($d, $data['version'] ?? null);
                if (! in_array($d->status, [DiscrepancyStatus::OPEN, DiscrepancyStatus::INVESTIGATING], true)) {
                    throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This discrepancy is already resolved.');
                }
                $unresolved = D::sub($d->base_quantity, $d->resolved_base_quantity);
                $qty = D::exact($data['quantity'] ?? $unresolved);
                if (D::gt($qty, $unresolved)) {
                    throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, 'The quantity exceeds the unresolved discrepancy.', ['unresolved' => $unresolved]);
                }
                if ($resolution === R::KEEP_IN_TRANSIT) {
                    $d->status->assertCanTransitionTo(DiscrepancyStatus::INVESTIGATING, 'discrepancy');
                    $d->forceFill(['status' => DiscrepancyStatus::INVESTIGATING, 'notes' => trim(($d->notes ?? '')."\n".$data['reason']), 'version' => $d->version + 1])->save();
                    $this->deriveStatus($to);
                    $ctx->audit('transfers.discrepancy.investigating', 'transfer_discrepancies', $d->id, null, null, $data['reason']);

                    return new PostingOutcome(['discrepancy_id' => $d->id, 'status' => $d->status->value], 200);
                }

                $movement = $this->postResolution($to, $d, $resolution, $qty, $data, $ctx);
                $d->forceFill(['resolved_base_quantity' => D::add($d->resolved_base_quantity, $qty), 'version' => $d->version + 1])->save();
                if (D::eq($d->resolved_base_quantity, $d->base_quantity)) {
                    $d->forceFill(['status' => DiscrepancyStatus::RESOLVED])->save();
                }
                if ($d->discrepancy_type === DiscrepancyType::SHORTAGE && $d->transfer_dispatch_line_id !== null) {
                    $dl = TransferDispatchLine::query()->lockForUpdate()->findOrFail($d->transfer_dispatch_line_id);
                    $credit = $resolution === R::RECEIVE_ACCEPTED || $resolution === R::RECORD_DAMAGE ? 'received_base_quantity' : 'resolved_base_quantity';
                    $dl->forceFill([$credit => D::add($dl->{$credit}, $qty)])->save();
                    $ol = TransferOrderLine::query()->lockForUpdate()->findOrFail($dl->transfer_order_line_id);
                    $ol->forceFill([$credit => D::add($ol->{$credit}, $qty)])->save();
                }
                TransferDiscrepancyResolution::query()->create([
                    'transfer_discrepancy_id' => $d->id, 'resolution' => $resolution, 'base_quantity' => $qty, 'stock_movement_id' => $movement?->id,
                    'reason' => $data['reason'], 'resolved_by' => $actor->id, 'resolved_at' => now(),
                ]);
                $this->deriveStatus($to);
                $ctx->audit('transfers.discrepancy.resolved', 'transfer_discrepancies', $d->id, null,
                    ['resolution' => $resolution->value, 'quantity' => $qty, 'movement_number' => $movement?->movement_number], $data['reason']);

                return new PostingOutcome(['discrepancy_id' => $d->id, 'status' => $d->refresh()->status->value, 'stock_movement_id' => $movement?->id,
                    'transfer_status' => $to->refresh()->status->value], 201);
            }, failureAudit: ['transfers.discrepancy.resolve_failed', 'transfer_discrepancies', $discrepancyId]);
    }

    /** Close when every dispatched unit is accounted for and no discrepancy is open. */
    public function close(string $id, UserAccount $actor, ?int $version = null): TransferOrder
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $to = $this->lockDocument(TransferOrder::class, $id);
            $this->authorizeEitherWarehouse($actor, 'transfers.transfer.close', $to->source_warehouse_id, $to->destination_warehouse_id);
            $this->expectVersion($to, $version);
            $lines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->lockForUpdate()->get();
            foreach ($lines as $line) {
                $accounted = D::add($line->received_base_quantity, $line->resolved_base_quantity);
                if (! D::eq($accounted, $line->dispatched_base_quantity)) {
                    throw DomainException::of(ErrorCode::CONSERVATION_VIOLATION,
                        "Line {$line->line_no} still has ".D::trim(D::sub($line->dispatched_base_quantity, $accounted)).' in transit.',
                        ['line' => $line->line_no, 'dispatched' => $line->dispatched_base_quantity, 'received' => $line->received_base_quantity, 'resolved' => $line->resolved_base_quantity],
                        'Receive the remainder or resolve it through a discrepancy before closing.');
                }
            }
            if (TransferDiscrepancy::query()->where('transfer_order_id', $to->id)->whereIn('status', ['open', 'investigating'])->exists()) {
                throw DomainException::of(ErrorCode::CONSERVATION_VIOLATION, 'Resolve all open discrepancies before closing the transfer.');
            }
            foreach ($lines as $line) {
                $open = D::sub(D::sub($line->approved_base_quantity, $line->dispatched_base_quantity), $line->cancelled_base_quantity);
                if (D::isPositive($open)) {
                    $line->forceFill(['cancelled_base_quantity' => D::add($line->cancelled_base_quantity, $open)])->save();
                }
            }
            $this->reservations->releaseForReference(ReservationReferenceType::TRANSFER_ORDER, $to->id, $actor, 'Transfer closed');
            if ($to->status !== S::RECEIVED && $to->status !== S::DISCREPANCY_REVIEW) {
                $this->deriveStatus($to);
            }
            $this->transition($to->refresh(), S::CLOSED, ['closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit($actor, 'transfers.transfer.closed', $to);

            return $to;
        });
    }

    /** Re-derive the in-flight status from line quantities and open discrepancies (Chapter 08 §8.4). */
    public function deriveStatus(TransferOrder $to): void
    {
        if (in_array($to->status, [S::DRAFT, S::SUBMITTED, S::CANCELLED, S::CLOSED], true)) {
            return;
        }
        $lines = TransferOrderLine::query()->where('transfer_order_id', $to->id)->get();
        $dispatched = D::sum($lines->pluck('dispatched_base_quantity'));
        $accounted = D::add(D::sum($lines->pluck('received_base_quantity')), D::sum($lines->pluck('resolved_base_quantity')));
        $fullyDispatched = $lines->every(fn ($l) => D::gte(D::add($l->dispatched_base_quantity, $l->cancelled_base_quantity), $l->approved_base_quantity));
        $openDiscrepancies = TransferDiscrepancy::query()->where('transfer_order_id', $to->id)->whereIn('status', ['open', 'investigating'])->exists();

        $target = match (true) {
            ! D::isPositive($dispatched) => $to->status === S::PICKING ? S::PICKING : S::APPROVED,
            $openDiscrepancies => S::DISCREPANCY_REVIEW,
            ! D::isPositive($accounted) => $fullyDispatched ? S::IN_TRANSIT : S::PARTIALLY_DISPATCHED,
            D::lt($accounted, $dispatched) || ! $fullyDispatched => S::PARTIALLY_RECEIVED,
            default => S::RECEIVED,
        };
        if ($target !== $to->status) {
            $this->transition($to, $target);
        }
    }

    // ------------------------------------------------------------------------------------------

    private function postResolution(TransferOrder $to, TransferDiscrepancy $d, R $resolution, string $qty, array $data, PostingContext $ctx): ?\App\Domains\Inventory\Models\StockMovement
    {
        $transit = $this->locations->inTransit($to->source_warehouse_id, $to->destination_warehouse_id)->id;
        $destination = Warehouse::query()->findOrFail($to->destination_warehouse_id);
        $source = Warehouse::query()->findOrFail($to->source_warehouse_id);
        $dl = $d->transfer_dispatch_line_id ? TransferDispatchLine::query()->find($d->transfer_dispatch_line_id) : null;
        $variant = $dl?->item_variant_id;
        $line = fn (string $from, StockCondition $fromCond, string $toLoc, StockCondition $toCond, ?string $cost = null, bool $fixed = false) => new MovementLine(
            $d->item_id, $variant, $from, $fromCond, $toLoc, $toCond, $qty, lotId: $d->inventory_lot_id, serialId: $d->item_serial_id,
            unitCost: $cost, fixedCost: $fixed, sourceLineType: 'transfer_discrepancy', sourceLineId: $d->id);

        if ($d->discrepancy_type === DiscrepancyType::EXCESS) {
            if ($resolution !== R::RECEIVE_ACCEPTED) {
                return null; // excess not taken into stock: nothing moves
            }
            $storage = $this->destinationLocation($destination, $data['destination_storage_location_id'] ?? null, StorageLocationUsage::RECEIVING, 0);
            $mv = [$line($this->locations->virtual(InventoryLocationType::ADJUSTMENT)->id, StockCondition::AVAILABLE, $this->locations->forStorageLocation($storage->id)->id, StockCondition::AVAILABLE)];
            $type = MovementType::ADJUSTMENT_IN;
            $warehouseId = $destination->id;
        } elseif ($d->discrepancy_type === DiscrepancyType::DAMAGE) {
            return null; // damage was already moved to the damaged location at receipt
        } else {
            [$type, $mv, $warehouseId] = match ($resolution) {
                R::RECEIVE_ACCEPTED => [MovementType::TRANSFER_RECEIPT, [$line($transit, StockCondition::AVAILABLE,
                    $this->locations->forStorageLocation($this->destinationLocation($destination, $data['destination_storage_location_id'] ?? null, StorageLocationUsage::RECEIVING, 0)->id)->id,
                    StockCondition::AVAILABLE)], $destination->id],
                R::RECORD_DAMAGE => [MovementType::TRANSFER_RECEIPT, [$line($transit, StockCondition::AVAILABLE,
                    $this->locations->forStorageLocation($this->destinationLocation($destination, null, StorageLocationUsage::DAMAGED, 0)->id)->id, StockCondition::DAMAGED)], $destination->id],
                R::LOSS_ADJUSTMENT => [MovementType::TRANSFER_LOSS, [$line($transit, StockCondition::AVAILABLE,
                    $this->locations->virtual(InventoryLocationType::INVENTORY_LOSS)->id, StockCondition::AVAILABLE)], $source->id],
                R::RETURN_TO_SOURCE, R::DATA_CORRECTION_REVERSAL => [MovementType::TRANSFER_RETURN, [$line($transit, StockCondition::AVAILABLE,
                    $this->locations->forStorageLocation($this->destinationLocation($source, $dl?->source_storage_location_id, StorageLocationUsage::STORAGE, 0)->id)->id,
                    StockCondition::AVAILABLE, $dl?->unit_cost_snapshot, $dl !== null)], $source->id],
                default => throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unsupported resolution.'),
            };
        }
        $eventId = (string) Str::uuid7();

        return $this->poster->post(new MovementRequest($type, DocumentType::TRANSFER_ORDER, $to->id, $to->document_number, $eventId, $mv,
            warehouseId: $warehouseId, counterpartyWarehouseId: $warehouseId === $source->id ? $destination->id : $source->id,
            reasonText: $resolution->value.': '.$data['reason']), $ctx);
    }

    /** @param  array<string, mixed>  $row */
    private function verifyBatch(TransferDispatchLine $dl, array $row, BatchVerifyStrictness $strictness, int $i): void
    {
        if ($dl->inventory_lot_id !== null) {
            $expected = InventoryLot::query()->whereKey($dl->inventory_lot_id)->value('lot_number');
            $scanned = $row['scanned_lot_number'] ?? null;
            if ($scanned === null || strcasecmp(trim($scanned), (string) $expected) !== 0) {
                throw DomainException::of(ErrorCode::LOT_MISMATCH, "Line ".($i + 1).": scanned lot does not match the dispatched lot $expected.",
                    ['field' => "lines.$i.scanned_lot_number", 'expected' => $expected, 'scanned' => $scanned]);
            }
        }
        if ($dl->item_serial_id !== null && $strictness === BatchVerifyStrictness::LOT_AND_SERIAL) {
            $expected = ItemSerial::query()->whereKey($dl->item_serial_id)->value('serial_number');
            $scanned = $row['scanned_serial_number'] ?? null;
            if ($scanned === null || strcasecmp(trim($scanned), (string) $expected) !== 0) {
                throw DomainException::of(ErrorCode::LOT_MISMATCH, "Line ".($i + 1).": scanned serial does not match the dispatched serial $expected.",
                    ['field' => "lines.$i.scanned_serial_number", 'expected' => $expected, 'scanned' => $scanned]);
            }
        }
    }

    private function destinationLocation(Warehouse $warehouse, ?string $requested, StorageLocationUsage $usage, int $i): StorageLocation
    {
        $id = $requested ?? $warehouse->defaultLocationId($usage) ?? $warehouse->default_receiving_location_id;
        $location = StorageLocation::query()->findOrFail($id);
        if ($location->warehouse_id !== $warehouse->id) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The location belongs to another warehouse.', ['field' => "lines.$i.destination_storage_location_id"]);
        }

        return $location;
    }

    private function authorizeSide(UserAccount $actor, string $permission, TransferSide $side, string $sourceId, string $destinationId): void
    {
        match ($side) {
            TransferSide::SOURCE => $this->authorizeWarehouse($actor, $permission, $sourceId),
            TransferSide::DESTINATION => $this->authorizeWarehouse($actor, $permission, $destinationId),
            TransferSide::BOTH => [$this->authorizeWarehouse($actor, $permission, $sourceId), $this->authorizeWarehouse($actor, $permission, $destinationId)],
            TransferSide::EITHER => $this->authorizeEitherWarehouse($actor, $permission, $sourceId, $destinationId),
        };
    }

    /** @param  \Illuminate\Support\Collection<string, TransferOrderLine>  $lines @param  array<string, string>  $approved */
    private function transferValue($lines, array $approved): string
    {
        $value = '0';
        foreach ($lines as $line) {
            $cost = (string) (DB::table('items')->where('id', $line->item_id)->value('current_average_cost') ?? '0');
            $value = D::add($value, D::mul($approved[$line->id], $cost, D::MONEY_SCALE), D::MONEY_SCALE);
        }

        return $value;
    }
}
