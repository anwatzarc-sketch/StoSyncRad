<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Catalog\Models\Item;
use App\Domains\Fulfillment\Services\StockIssueWorkflow;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Receiving\Enums\PutawayTaskStatus;
use App\Domains\Receiving\Models\PutawayTask;
use App\Domains\Transfers\Enums\DiscrepancyStatus;
use App\Domains\Transfers\Models\TransferDiscrepancy;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory maintenance endpoints (Chapter 07): lot registration/update/lock and QC decisions,
 * serial registration, manual reservations, valuation summary, put-away assignment and
 * discrepancy investigation. Each is bound to its own permission.
 */
final class OperationsController extends ApiController
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Access $access) {}

    public function registerLot(Request $request, LotService $lots): JsonResponse
    {
        $actor = $this->actor($request);
        $item = Item::query()->findOrFail((string) $request->input('item_id'));
        $lot = DB::transaction(fn () => $lots->resolveForReceipt($item, $request->input('item_variant_id'), $request->input('lot_number'), $request->input('supplier_id'),
            $request->input('manufacture_date'), $request->input('expiry_date'), 'manual_registration', (string) Str::uuid7(), $actor->id,
            array_filter(['supplier_lot_reference' => $request->input('supplier_lot_reference')])));
        $this->audit->record($actor, 'inventory.lot.registered', 'inventory_lots', $lot->id, null, ['lot_number' => $lot->lot_number]);

        return $this->respond($lot, 201);
    }

    public function updateLot(Request $request, LotService $lots, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $lot = DB::transaction(function () use ($request, $lots, $id, $actor) {
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($id);
            $before = $lot->only(['expiry_date', 'manufacture_date', 'best_before_date', 'supplier_lot_reference']);
            foreach (['expiry_date', 'manufacture_date'] as $d) {
                if ($request->has($d) && $request->input($d) !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($d))) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Dates use YYYY-MM-DD.', ['field' => $d]);
                }
            }
            $expiry = $request->has('expiry_date') ? $request->input('expiry_date') : $lot->expiry_date?->toDateString();
            $derived = $lots->derivedDates(Item::query()->findOrFail($lot->item_id), $expiry);
            $lot->forceFill(['expiry_date' => $expiry, 'manufacture_date' => $request->has('manufacture_date') ? $request->input('manufacture_date') : $lot->manufacture_date,
                'supplier_lot_reference' => $request->input('supplier_lot_reference', $lot->supplier_lot_reference), 'best_before_date' => $derived['best_before'],
                'removal_date' => $derived['removal'], 'alert_date' => $derived['alert'], 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, 'inventory.lot.updated', 'inventory_lots', $lot->id, $before, $lot->only(array_keys($before)), $request->input('reason'));

            return $lot;
        });

        return $this->respond($lot);
    }

    public function lockLot(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $lock = (string) $request->route()->getAction('variant') === 'lock';
        $lot = DB::transaction(function () use ($id, $lock, $actor, $request) {
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($id);
            if ($lock) {
                if ($lot->lock_status !== LotLockStatus::UNLOCKED) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Lot {$lot->lot_number} is already {$lot->lock_status->value}.");
                }
                if (trim((string) $request->input('reason')) === '') {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Give the investigation reason.', ['field' => 'reason']);
                }
                $lot->forceFill(['lock_status' => LotLockStatus::LOCKED_INVESTIGATION])->save();
            } else {
                if ($lot->lock_status !== LotLockStatus::LOCKED_INVESTIGATION) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Only investigation locks are lifted here (recalls close through the recall; transit locks through receipt).');
                }
                $lot->forceFill(['lock_status' => LotLockStatus::UNLOCKED])->save();
            }
            $this->audit->record($actor, $lock ? 'inventory.lot.locked' : 'inventory.lot.unlocked', 'inventory_lots', $lot->id, null, null, $request->input('reason'));

            return $lot;
        });

        return $this->respond($lot);
    }

    /** QC decision on a lot; release also moves its quarantined stock to available. */
    public function qcDecision(Request $request, PostingPipeline $pipeline, InventoryPoster $poster, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = LotQcStatus::tryFrom((string) $request->input('decision')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed: released, rejected, conditional.', ['field' => 'decision']);
        if ($decision === LotQcStatus::PENDING) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose released, rejected or conditional.', ['field' => 'decision']);
        }
        $outcome = $pipeline->run($actor, 'receiving.qc.release', $this->idempotencyKey($request), ['lot_id' => $id, 'decision' => $decision->value],
            function (PostingContext $ctx) use ($id, $decision, $actor, $poster, $request) {
                $lot = InventoryLot::query()->lockForUpdate()->findOrFail($id);
                $lot->forceFill(['qc_status' => $decision, 'qc_released_by' => $actor->id, 'qc_released_at' => now()])->save();
                $movement = null;
                if (in_array($decision, [LotQcStatus::RELEASED, LotQcStatus::CONDITIONAL], true)) {
                    $positions = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
                        ->where('b.inventory_lot_id', $lot->id)->where('b.stock_condition', StockCondition::QUARANTINE->value)->where('il.location_type', 'physical')
                        ->where('b.quantity_on_hand', '>', 0)->orderBy('b.id')->get(['b.item_id', 'b.item_variant_id', 'b.inventory_location_id', 'b.warehouse_id', 'b.quantity_on_hand']);
                    foreach ($positions->groupBy('warehouse_id') as $warehouseId => $group) {
                        if (! $this->access->can($actor, 'receiving.qc.release', $warehouseId)) {
                            throw DomainException::permissionDenied('receiving.qc.release', $warehouseId);
                        }
                        $lines = $group->map(fn ($p) => new MovementLine($p->item_id, Sentinel::toNull($p->item_variant_id), $p->inventory_location_id, StockCondition::QUARANTINE,
                            $p->inventory_location_id, StockCondition::AVAILABLE, D::exact((string) $p->quantity_on_hand), lotId: $lot->id, sourceLineType: 'qc_release'))->all();
                        $movement = $poster->post(new MovementRequest(MovementType::CONDITION_CHANGE, DocumentType::STOCK_MOVEMENT, (string) Str::uuid7(),
                            'QC-'.$lot->lot_number, (string) Str::uuid7(), $lines, warehouseId: $warehouseId, reasonText: 'QC '.$decision->value.': '.$request->input('notes', '')), $ctx);
                    }
                }
                $ctx->audit('receiving.qc.decided', 'inventory_lots', $lot->id, null, ['decision' => $decision->value, 'movement' => $movement?->movement_number], $request->input('notes'));

                return new PostingOutcome(['lot_id' => $lot->id, 'qc_status' => $decision->value, 'movement_number' => $movement?->movement_number], 200);
            }, failureAudit: ['receiving.qc.decision_failed', 'inventory_lots', $id]);

        return $this->respond($outcome);
    }

    public function registerSerial(Request $request, LotService $lots): JsonResponse
    {
        $actor = $this->actor($request);
        $item = Item::query()->findOrFail((string) $request->input('item_id'));
        if (! $item->tracking_mode->requiresSerial()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is not serial-tracked.", ['field' => 'item_id']);
        }
        $serial = DB::transaction(fn () => $lots->resolveSerial($item, $request->input('item_variant_id'), trim((string) $request->input('serial_number')), $request->input('inventory_lot_id')));
        if ($request->filled('warranty_until')) {
            $serial->forceFill(['warranty_until' => $request->input('warranty_until')])->save();
        }
        $this->audit->record($actor, 'inventory.serial.registered', 'item_serials', $serial->id, null, ['serial_number' => $serial->serial_number]);

        return $this->respond($serial, 201);
    }

    public function updateSerial(Request $request, string $id): JsonResponse
    {
        $serial = ItemSerial::query()->findOrFail($id);
        $before = $serial->only(['warranty_until', 'attributes']);
        $serial->forceFill(['warranty_until' => $request->input('warranty_until', $serial->warranty_until),
            'attributes' => $request->has('attributes') ? (array) $request->input('attributes') : $serial->attributes])->save();
        $this->audit->record($this->actor($request), 'inventory.serial.updated', 'item_serials', $serial->id, $before, $serial->only(['warranty_until', 'attributes']));

        return $this->respond($serial);
    }

    /** Manual hold of available stock (e.g. for a customer promise), FEFO/FIFO positions. */
    public function reserve(Request $request, ReservationService $reservations): JsonResponse
    {
        $actor = $this->actor($request);
        $warehouseId = (string) $request->input('warehouse_id');
        if (! $this->access->can($actor, 'inventory.reservation.create', $warehouseId)) {
            throw DomainException::permissionDenied('inventory.reservation.create', $warehouseId);
        }
        $item = Item::query()->findOrFail((string) $request->input('item_id'));
        $qty = (string) $request->input('quantity');
        is_numeric($qty) && D::isPositive($qty) || throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Quantity must be positive.', ['field' => 'quantity']);
        $reference = (string) Str::uuid7();
        $made = DB::transaction(fn () => $reservations->reserveFromWarehouse(ReservationReferenceType::MANUAL, $reference, $reference, $item, $request->input('item_variant_id'),
            $warehouseId, D::exact($qty), $actor, lotId: $request->input('lot_id'),
            expiresAt: $request->filled('expires_at') ? CarbonImmutable::parse((string) $request->input('expires_at')) : now()->addDays(7)->toImmutable()));
        $this->audit->record($actor, 'inventory.reservation.created', 'stock_reservations', $made[0]->id, null, ['quantity' => $qty, 'note' => $request->input('note')]);

        return response()->json(['data' => $this->presenter()->collection($made), 'meta' => ['reference_id' => $reference]], 201);
    }

    public function releaseReservation(Request $request, ReservationService $reservations, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $r = StockReservation::query()->findOrFail($id);
        if (! $this->access->can($actor, 'inventory.reservation.release', $r->warehouse_id)) {
            throw DomainException::permissionDenied('inventory.reservation.release', $r->warehouse_id);
        }
        DB::transaction(fn () => $reservations->release($r, $actor, (string) $request->input('reason', 'Released manually'), $request->input('quantity')));

        return $this->respond($r->refresh());
    }

    public function adjustReservation(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $r = StockReservation::query()->findOrFail($id);
        if (! $this->access->can($actor, 'inventory.reservation.adjust', $r->warehouse_id)) {
            throw DomainException::permissionDenied('inventory.reservation.adjust', $r->warehouse_id);
        }
        $expires = $request->input('expires_at');
        $r->forceFill(['expires_at' => $expires === null ? null : CarbonImmutable::parse((string) $expires), 'version' => $r->version + 1])->save();
        $this->audit->record($actor, 'inventory.reservation.adjusted', 'stock_reservations', $r->id, null, ['expires_at' => $expires], $request->input('reason'));

        return $this->respond($r);
    }

    public function valuation(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $allowed = $this->access->warehouseIdsFor($actor, 'inventory.valuation.read');
        $rows = DB::table('item_cost_projections as p')->join('items as i', 'i.id', '=', 'p.item_id')->leftJoin('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->where('p.quantity', '<>', 0)
            ->when($allowed !== '*', fn ($q) => $q->whereIn('p.warehouse_id', $allowed === [] ? [Sentinel::UUID] : $allowed))
            ->when($request->filled('item_id'), fn ($q) => $q->where('p.item_id', (string) $request->query('item_id')))
            ->orderByDesc('p.total_value')->limit(1000)
            ->get(['p.item_id', 'i.sku', 'i.name', 'i.costing_method', 'p.warehouse_id', 'w.code as warehouse_code', 'p.quantity', 'p.total_value', 'p.average_cost']);

        return response()->json(['data' => $rows->map(fn ($r) => ['warehouse_id' => Sentinel::toNull($r->warehouse_id)] + (array) $r)->all(),
            'meta' => ['total_value' => D::sum($rows->pluck('total_value')->map(fn ($v) => (string) $v)->all(), D::MONEY_SCALE)]]);
    }

    public function assignPutaway(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $task = PutawayTask::query()->findOrFail($id);
        if (! $this->access->can($actor, 'receiving.putaway.assign', $task->warehouse_id)) {
            throw DomainException::permissionDenied('receiving.putaway.assign', $task->warehouse_id);
        }
        if ($task->status !== PutawayTaskStatus::OPEN) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Only open tasks can be assigned.');
        }
        $task->forceFill(['assigned_to' => $request->input('assigned_to')])->save();
        $this->audit->record($actor, 'receiving.putaway.assigned', 'putaway_tasks', $task->id, null, ['assigned_to' => $task->assigned_to]);

        return $this->respond($task);
    }

    public function investigateDiscrepancy(Request $request, string $transferId, string $discrepancyId): JsonResponse
    {
        $actor = $this->actor($request);
        $d = TransferDiscrepancy::query()->where('transfer_order_id', $transferId)->findOrFail($discrepancyId);
        if ($d->status !== DiscrepancyStatus::OPEN) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Only open discrepancies can be put under investigation.');
        }
        $d->forceFill(['status' => DiscrepancyStatus::INVESTIGATING, 'notes' => trim(($d->notes ? $d->notes."\n" : '').(string) $request->input('notes')), 'version' => $d->version + 1])->save();
        $this->audit->record($actor, 'transfers.discrepancy.investigating', 'transfer_discrepancies', $d->id, null, null, $request->input('notes'));

        return $this->respond($d);
    }

    /** Draft issue of materials to production (posted through the stock-issue actions). */
    public function productionIssue(Request $request, StockIssueWorkflow $issues): JsonResponse
    {
        return $this->respond($issues->save(['is_production_issue' => true] + $this->payload($request), $this->actor($request))->load('lines'), 201);
    }
}
