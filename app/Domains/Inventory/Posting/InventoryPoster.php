<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Posting;

use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\MovementStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryBalance;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\ValuationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Models\InventoryLocation;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\DocumentNumberAllocator;
use App\Support\Enums\EnforcementLevel;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use App\Support\Testing\FaultInjector;
use App\Support\Transactions\PostingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single writer of the inventory ledger (Chapter 07 §7.13, Chapter 09 §9.2 steps 7–11).
 *
 * Must run inside a PostingPipeline transaction. In one pass it:
 *   locks positions (global order) → lots → serials → reservations → cost projections,
 *   revalidates tracking, lot/serial/condition eligibility, frozen positions and availability
 *   UNDER THOSE LOCKS, writes the movement header and lines, updates balance projections,
 *   reservations, serial positions and valuation, and publishes the outbox event.
 *
 * External (virtual) locations — supplier, customer, adjustment, opening balance, scrap,
 * production, inventory loss — are unlimited sources/sinks and hold no balances. Owned
 * locations (physical, in-transit, return inspection, cross-dock) hold balances.
 *
 * In-transit stock is lot-locked at position level: stock in an in-transit location can only
 * leave through transfer receipt / loss / return movements or a reversal (Chapter 07 §7.10).
 */
final class InventoryPoster
{
    /** Movement types allowed to take stock out of an in-transit location. */
    private const IN_TRANSIT_EXITS = [MovementType::TRANSFER_RECEIPT, MovementType::TRANSFER_LOSS, MovementType::TRANSFER_RETURN, MovementType::REVERSAL];

    /** Movement types that consume available stock (eligibility rules apply). */
    private const CONSUMING = [MovementType::ISSUE, MovementType::SALE_DELIVERY, MovementType::TRANSFER_DISPATCH, MovementType::PRODUCTION_ISSUE,
        MovementType::SUPPLIER_RETURN];

    public function __construct(
        private readonly BalanceLocker $locker,
        private readonly ValuationService $valuation,
        private readonly CategoryPolicyResolver $policies,
        private readonly DocumentNumberAllocator $numbers,
        private readonly PlatformConfig $config,
    ) {}

    public function post(MovementRequest $req, PostingContext $ctx): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('InventoryPoster::post() must run inside a posting transaction.');
        }

        if ($req->businessDate !== null) {    // postings use "now"; only the business date may be earlier, within the window
            $date = \Carbon\CarbonImmutable::parse($req->businessDate)->startOfDay();
            $today = now()->toImmutable()->startOfDay();
            $window = $this->config->int('stock.backdating_window_days');
            if ($date->gt($today) || $date->lt($today->subDays($window))) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "The business date must be between {$today->subDays($window)->toDateString()} and today.",
                    ['field' => 'document_date', 'backdating_window_days' => $window]);
            }
        }

        // ---- 1. Reference data -------------------------------------------------------------
        $locationIds = [];
        foreach ($req->lines as $line) {
            $locationIds[$line->fromLocationId] = true;
            $locationIds[$line->toLocationId] = true;
        }
        /** @var array<string, InventoryLocation> $locations */
        $locations = InventoryLocation::query()->whereIn('id', array_keys($locationIds))->get()->keyBy('id')->all();
        foreach (array_keys($locationIds) as $id) {
            if (! isset($locations[$id]) || ! $locations[$id]->is_active) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'An inventory location in this posting is missing or inactive.', ['inventory_location_id' => $id]);
            }
        }

        $itemIds = array_values(array_unique(array_map(static fn (MovementLine $l) => $l->itemId, $req->lines)));
        /** @var array<string, Item> $items */
        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id')->all();

        // ---- 2. Static validation ----------------------------------------------------------
        foreach ($req->lines as $i => $line) {
            $item = $items[$line->itemId] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown item.', ['line' => $i, 'item_id' => $line->itemId]);
            $from = $locations[$line->fromLocationId];
            $to = $locations[$line->toLocationId];
            $this->validateLine($req, $line, $item, $from, $to, $i);
        }

        FaultInjector::point('after_document_validation');

        // ---- 3. Lock positions (global order) ---------------------------------------------
        $keys = [];
        $warehouseByLocation = [];
        foreach ($req->lines as $line) {
            foreach ([[$line->from(), $locations[$line->fromLocationId]], [$line->to(), $locations[$line->toLocationId]]] as [$key, $loc]) {
                if ($loc->location_type->holdsOwnedStock()) {
                    $keys[] = $key;
                    $warehouseByLocation[$loc->id] = $loc->warehouse_id;
                }
            }
        }
        $balances = $this->locker->lock($keys, $warehouseByLocation);
        FaultInjector::point('after_position_locks');

        // ---- 4. Lots ---------------------------------------------------------------------
        $lotIds = array_values(array_unique(array_filter(array_map(static fn (MovementLine $l) => $l->lotId, $req->lines))));
        sort($lotIds, SORT_STRING);
        /** @var array<string, InventoryLot> $lots */
        $lots = $lotIds === [] ? [] : InventoryLot::query()->whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
        foreach ($req->lines as $i => $line) {
            if ($line->lotId !== null) {
                $lot = $lots[$line->lotId] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown lot.', ['line' => $i]);
                $this->validateLot($req, $line, $lot, $locations[$line->fromLocationId], $i);
            }
        }

        // ---- 5. Serials ------------------------------------------------------------------
        $serialIds = array_values(array_unique(array_filter(array_map(static fn (MovementLine $l) => $l->serialId, $req->lines))));
        sort($serialIds, SORT_STRING);
        /** @var array<string, ItemSerial> $serials */
        $serials = $serialIds === [] ? [] : ItemSerial::query()->whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
        if (count($serialIds) !== count(array_filter(array_map(static fn (MovementLine $l) => $l->serialId, $req->lines)))) {
            throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, 'A serial number appears more than once in this posting.');
        }
        foreach ($req->lines as $i => $line) {
            if ($line->serialId !== null) {
                $serial = $serials[$line->serialId] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown serial.', ['line' => $i]);
                $this->validateSerial($req, $line, $serial, $locations[$line->fromLocationId], $i);
            }
        }

        // ---- 6. Reservations being consumed ------------------------------------------------
        $reservationIds = [];
        foreach ($req->lines as $line) {
            foreach (array_keys($line->consumeReservations) as $rid) {
                $reservationIds[$rid] = true;
            }
        }
        $reservationIds = array_keys($reservationIds);
        sort($reservationIds, SORT_STRING);
        $reservations = $reservationIds === [] ? [] : DB::table('stock_reservations')->whereIn('id', $reservationIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();

        // ---- 7. Availability, frozen positions, negative-stock policy (under locks) --------
        $required = [];              // sortKey → qty leaving
        $consumedHere = [];          // sortKey → reserved qty consumed by this posting
        $consumedByReservation = []; // reservation id → qty consumed by this posting
        $allowNegative = [];         // sortKey → bool
        foreach ($req->lines as $i => $line) {
            $fromLoc = $locations[$line->fromLocationId];
            $toLoc = $locations[$line->toLocationId];
            foreach ([$line->from(), $line->to()] as $key) {
                $balance = $balances[$key->sortKey()] ?? null;
                if ($balance !== null && $balance->is_frozen && ! in_array($balance->frozen_by_stocktake_id, $req->permittedFrozenStocktakeIds, true)) {
                    throw DomainException::of(ErrorCode::POSITION_FROZEN, 'This stock position is frozen by an open stocktake.',
                        ['line' => $i, 'stocktake_id' => $balance->frozen_by_stocktake_id]);
                }
            }
            if (! $fromLoc->location_type->holdsOwnedStock()) {
                continue;
            }
            $k = $line->from()->sortKey();
            $required[$k] = D::add($required[$k] ?? '0', $line->quantity);
            foreach ($line->consumeReservations as $rid => $qty) {
                $r = $reservations[$rid] ?? throw DomainException::of(ErrorCode::RESERVATION_CONFLICT, 'Reservation not found.', ['reservation_id' => $rid]);
                if ($r->inventory_balance_id !== $balances[$k]->id) {
                    throw DomainException::of(ErrorCode::RESERVATION_CONFLICT, 'Reservation does not belong to the source position.', ['reservation_id' => $rid, 'line' => $i]);
                }
                if (! in_array($r->status, [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value], true)) {
                    throw DomainException::of(ErrorCode::RESERVATION_CONFLICT, 'Reservation is no longer active.', ['reservation_id' => $rid, 'status' => $r->status]);
                }
                $outstanding = D::sub(D::sub($r->quantity_reserved, $r->quantity_consumed), $r->quantity_released);
                $already = $consumedByReservation[$rid] ?? '0';
                if (D::gt(D::add($already, $qty), $outstanding)) {
                    throw DomainException::of(ErrorCode::RESERVATION_CONFLICT, 'Consumption exceeds the outstanding reserved quantity.',
                        ['reservation_id' => $rid, 'outstanding' => $outstanding]);
                }
                $consumedByReservation[$rid] = D::add($already, $qty);
                $consumedHere[$k] = D::add($consumedHere[$k] ?? '0', $qty);
            }
            $allowNegative[$k] = ($allowNegative[$k] ?? false) || $line->allowNegative;
        }
        foreach ($required as $k => $qty) {
            $b = $balances[$k];
            $available = D::add(D::sub($b->quantity_on_hand, $b->quantity_reserved), $consumedHere[$k] ?? '0');
            if (D::gt($qty, $available) && ! ($allowNegative[$k] ?? false)) {
                $item = $items[$b->item_id];
                throw DomainException::insufficientStock($item->label(), D::trim($qty), D::trim($available), [
                    'item_id' => $b->item_id, 'inventory_location_id' => $b->inventory_location_id,
                    'inventory_lot_id' => Sentinel::toNull($b->inventory_lot_id), 'stock_condition' => $b->stock_condition->value,
                ]);
            }
        }

        // ---- 8. Valuation plan ------------------------------------------------------------
        $lineIds = [];
        $projectionKeys = [];
        foreach ($req->lines as $i => $line) {
            $lineIds[$i] = (string) Str::uuid7();
            foreach ([$locations[$line->fromLocationId], $locations[$line->toLocationId]] as $loc) {
                $bucket = $this->valuation->bucket($loc);
                if ($bucket !== null) {
                    $projectionKeys[] = [$line->itemId, $line->variantId, $bucket];
                }
            }
        }
        $this->valuation->lock($projectionKeys);
        $unitCosts = [];
        foreach ($req->lines as $i => $line) {
            $unitCosts[$i] = $this->value($req, $line, $items[$line->itemId], $locations[$line->fromLocationId], $locations[$line->toLocationId], $lineIds[$i], $i);
        }

        // ---- 9. Ledger --------------------------------------------------------------------
        $now = now();
        $movementId = (string) Str::uuid7();
        $currency = (string) $this->config->get('locale.currency.base');
        DB::table('stock_movements')->insert([
            'id' => $movementId,
            'movement_number' => $this->numbers->next(DocumentType::STOCK_MOVEMENT, ['warehouse_id' => $req->warehouseId]),
            'movement_type' => $req->type->value,
            'source_document_type' => $req->documentType->value,
            'source_document_id' => $req->documentId,
            'source_document_number' => $req->documentNumber,
            'posting_event_id' => $req->postingEventId,
            'idempotency_key_id' => $ctx->idempotencyKeyId,
            'status' => MovementStatus::POSTED->value,
            'posting_actor_id' => $ctx->actorId(),
            'posted_at' => $now,
            'business_date' => $req->businessDate ?? $now->toDateString(),
            'reversal_of_movement_id' => $req->reversalOfMovementId,
            'reason_lookup_value_id' => $req->reasonLookupValueId,
            'reason_text' => $req->reasonText,
            'correlation_id' => $ctx->correlationId,
            'warehouse_id' => $req->warehouseId,
            'counterparty_warehouse_id' => $req->counterpartyWarehouseId,
            'partner_id' => $req->partnerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $rows = [];
        foreach ($req->lines as $i => $line) {
            $item = $items[$line->itemId];
            $rows[] = [
                'id' => $lineIds[$i],
                'stock_movement_id' => $movementId,
                'line_no' => $i + 1,
                'item_id' => $line->itemId,
                'item_variant_id' => $line->variantId,
                'source_inventory_location_id' => $line->fromLocationId,
                'destination_inventory_location_id' => $line->toLocationId,
                'source_condition' => $line->fromCondition->value,
                'destination_condition' => $line->toCondition->value,
                'inventory_lot_id' => $line->lotId,
                'item_serial_id' => $line->serialId,
                'quantity' => $line->quantity,
                'base_uom_id' => $item->base_uom_id,
                'document_uom_id' => $line->documentUomId,
                'document_quantity' => $line->documentQuantity,
                'conversion_factor_snapshot' => $line->conversionFactor,
                'unit_cost_snapshot' => $unitCosts[$i],
                'total_cost_snapshot' => D::mul($line->quantity, $unitCosts[$i], D::MONEY_SCALE),
                'currency' => $currency,
                'source_line_type' => $line->sourceLineType,
                'source_line_id' => $line->sourceLineId,
                'source_warehouse_id' => $locations[$line->fromLocationId]->warehouse_id,
                'destination_warehouse_id' => $locations[$line->toLocationId]->warehouse_id,
                'reversed_quantity' => '0',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('stock_movement_lines')->insert($chunk);
            FaultInjector::point('after_first_ledger_line');
        }

        // ---- 10. Projections ----------------------------------------------------------------
        $delta = [];        // sortKey → [on_hand Δ, reserved Δ, value Δ]
        foreach ($req->lines as $i => $line) {
            $value = D::mul($line->quantity, $unitCosts[$i], D::MONEY_SCALE);
            if ($locations[$line->fromLocationId]->location_type->holdsOwnedStock()) {
                $k = $line->from()->sortKey();
                $delta[$k] ??= ['0', '0', '0'];
                $delta[$k][0] = D::sub($delta[$k][0], $line->quantity);
                $delta[$k][2] = D::sub($delta[$k][2], $value, D::MONEY_SCALE);
                foreach ($line->consumeReservations as $qty) {
                    $delta[$k][1] = D::sub($delta[$k][1], $qty);
                }
            }
            if ($locations[$line->toLocationId]->location_type->holdsOwnedStock()) {
                $k = $line->to()->sortKey();
                $delta[$k] ??= ['0', '0', '0'];
                $delta[$k][0] = D::add($delta[$k][0], $line->quantity);
                $delta[$k][2] = D::add($delta[$k][2], $value, D::MONEY_SCALE);
            }
        }
        foreach ($delta as $k => [$dQty, $dRes, $dVal]) {
            $b = $balances[$k];
            $onHand = D::add($b->quantity_on_hand, $dQty);
            $reserved = D::add($b->quantity_reserved, $dRes);
            $value = D::add($b->total_value_projection, $dVal, D::MONEY_SCALE);
            if (D::isZero($onHand)) {
                $value = '0.0000';
            }
            $negative = D::isNegative($onHand) || D::gt($reserved, $onHand);
            DB::update('UPDATE inventory_balances SET quantity_on_hand = ?, quantity_reserved = ?, total_value_projection = ?,
                        unit_cost_projection = ?, allow_negative = ?, last_movement_at = ?, version = version + 1, updated_at = ? WHERE id = ?', [
                $onHand, $reserved, $value,
                D::isPositive($onHand) ? D::div($value, $onHand, D::COST_SCALE) : $b->unit_cost_projection,
                $negative || ($b->allow_negative && D::isNegative($b->quantity_on_hand)), $now, $now, $b->id,
            ]);
        }

        FaultInjector::point('after_balance_update');

        // ---- 11. Reservations consumed -----------------------------------------------------
        foreach ($consumedByReservation as $rid => $qty) {
            $r = $reservations[$rid];
            $consumed = D::add($r->quantity_consumed, $qty);
            $outstanding = D::sub(D::sub($r->quantity_reserved, $consumed), $r->quantity_released);
            DB::update('UPDATE stock_reservations SET quantity_consumed = ?, status = ?, version = version + 1, updated_at = ? WHERE id = ?', [
                $consumed, D::isZero($outstanding) ? ReservationStatus::CONSUMED->value : ReservationStatus::PARTIALLY_CONSUMED->value, $now, $rid,
            ]);
        }

        FaultInjector::point('after_reservation_change');

        // ---- 12. Serial positions -----------------------------------------------------------
        foreach ($req->lines as $line) {
            if ($line->serialId === null) {
                continue;
            }
            $to = $locations[$line->toLocationId];
            $status = $this->serialStatusAfter($req->type, $to, $line->toCondition);
            DB::update('UPDATE item_serials SET lifecycle_status = ?, current_inventory_location_id = ?, current_condition = ?, current_warehouse_id = ?,
                        inventory_lot_id = COALESCE(inventory_lot_id, ?), version = version + 1, updated_at = ? WHERE id = ?', [
                $status->value,
                $to->location_type->holdsOwnedStock() ? $to->id : null,
                $to->location_type->holdsOwnedStock() ? $line->toCondition->value : null,
                $to->warehouse_id,
                $line->lotId,
                $now,
                $line->serialId,
            ]);
        }

        // ---- 13. Valuation persistence, item bookkeeping, outbox ---------------------------
        $this->valuation->persist();
        DB::update('UPDATE items SET first_posted_at = ? WHERE id IN ('.implode(',', array_fill(0, count($itemIds), '?')).') AND first_posted_at IS NULL',
            array_merge([$now], $itemIds));
        $variantIds = array_values(array_unique(array_filter(array_map(static fn (MovementLine $l) => $l->variantId, $req->lines))));
        if ($variantIds !== []) {
            DB::update('UPDATE item_variants SET first_posted_at = ? WHERE id IN ('.implode(',', array_fill(0, count($variantIds), '?')).') AND first_posted_at IS NULL',
                array_merge([$now], $variantIds));
        }

        $movement = StockMovement::query()->findOrFail($movementId);
        $ctx->publish('inventory.movement.posted', 'stock_movement', $movementId, [
            'movement_number' => $movement->movement_number,
            'movement_type' => $req->type->value,
            'source_document_type' => $req->documentType->value,
            'source_document_id' => $req->documentId,
            'warehouse_id' => $req->warehouseId,
            'line_count' => count($req->lines),
        ]);
        FaultInjector::point('after_outbox');

        return $movement;
    }

    // ------------------------------------------------------------------------------------------

    private function validateLine(MovementRequest $req, MovementLine $line, Item $item, InventoryLocation $from, InventoryLocation $to, int $i): void
    {
        if (! $item->isStockTracked()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is not a stock-tracked item.", ['line' => $i]);
        }
        if (! in_array($item->status, [ItemStatus::ACTIVE, ItemStatus::INACTIVE], true)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is {$item->status->value} and cannot move.", ['line' => $i]);
        }
        if ($item->has_variants && $line->variantId === null) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} has variants; specify the variant.", ['line' => $i]);
        }
        if (! $from->location_type->holdsOwnedStock() && ! $to->location_type->holdsOwnedStock()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A movement line must touch at least one owned stock location.', ['line' => $i]);
        }
        if ($from->location_type === InventoryLocationType::IN_TRANSIT && ! in_array($req->type, self::IN_TRANSIT_EXITS, true)) {
            throw DomainException::of(ErrorCode::LOT_LOCKED, 'Stock in transit is locked until it is received or a discrepancy is resolved.', ['line' => $i]);
        }

        $tracking = $item->tracking_mode;
        if ($tracking === TrackingMode::LOT && $line->lotId === null) {
            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "{$item->label()} is lot-tracked; a lot is required.", ['line' => $i]);
        }
        if ($tracking === TrackingMode::SERIAL) {
            if ($line->serialId === null) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, "{$item->label()} is serial-tracked; one serial per unit is required.", ['line' => $i]);
            }
            if (! D::eq($line->quantity, '1')) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'Serialized lines must move exactly one unit.', ['line' => $i]);
            }
        }
        if ($tracking === TrackingMode::NONE && ($line->lotId !== null || $line->serialId !== null)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} is not lot/serial tracked.", ['line' => $i]);
        }
        if (in_array($req->type, self::CONSUMING, true) && $from->location_type->holdsOwnedStock() && ! $line->fromCondition->isIssuable()
            && ! ($req->type === MovementType::SUPPLIER_RETURN)) {
            throw DomainException::of(ErrorCode::STOCK_INELIGIBLE, "Stock in condition `{$line->fromCondition->value}` cannot be issued.", ['line' => $i]);
        }
    }

    private function validateLot(MovementRequest $req, MovementLine $line, InventoryLot $lot, InventoryLocation $from, int $i): void
    {
        if ($lot->item_id !== $line->itemId) {
            throw DomainException::of(ErrorCode::LOT_MISMATCH, 'The lot does not belong to this item.', ['line' => $i]);
        }
        if (in_array($lot->lock_status, [LotLockStatus::LOCKED_RECALL, LotLockStatus::LOCKED_INVESTIGATION], true) && ! $req->allowLockedLots) {
            throw DomainException::of(ErrorCode::LOT_LOCKED, "Lot {$lot->lot_number} is locked ({$lot->lock_status->value}).", ['line' => $i, 'lot_id' => $lot->id]);
        }
        if (! in_array($req->type, self::CONSUMING, true) || ! $from->location_type->holdsOwnedStock() || $req->type === MovementType::SUPPLIER_RETURN) {
            return;
        }
        if (! in_array($lot->qc_status, [LotQcStatus::RELEASED, LotQcStatus::CONDITIONAL], true)) {
            throw DomainException::of(ErrorCode::LOT_NOT_RELEASED, "Lot {$lot->lot_number} has not been released by quality control.", ['line' => $i, 'qc_status' => $lot->qc_status->value]);
        }
        $today = now()->startOfDay();
        if ($lot->expiry_date !== null && $lot->expiry_date->lte($today) && ! $req->allowExpired) {
            throw DomainException::of(ErrorCode::STOCK_INELIGIBLE, "Lot {$lot->lot_number} expired on {$lot->expiry_date->toDateString()}.", ['line' => $i]);
        }
        if ($lot->removal_date !== null && $lot->removal_date->lte($today) && ! $req->allowExpired) {
            $level = EnforcementLevel::from((string) $this->config->get('stock.expiry.enforce_removal_date', ['item' => $line->itemId]));
            if ($level === EnforcementLevel::BLOCK) {
                throw DomainException::of(ErrorCode::STOCK_INELIGIBLE, "Lot {$lot->lot_number} is past its removal date.", ['line' => $i]);
            }
        }
    }

    private function validateSerial(MovementRequest $req, MovementLine $line, ItemSerial $serial, InventoryLocation $from, int $i): void
    {
        if ($serial->item_id !== $line->itemId) {
            throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, 'The serial does not belong to this item.', ['line' => $i]);
        }
        if ($from->location_type->holdsOwnedStock()) {
            if ($serial->current_inventory_location_id !== $from->id || $serial->current_condition !== $line->fromCondition) {
                throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$serial->serial_number} is not at the source position.", ['line' => $i]);
            }
            if (! in_array($serial->lifecycle_status, [SerialStatus::IN_STOCK, SerialStatus::RESERVED, SerialStatus::IN_TRANSIT, SerialStatus::RETURNED_PENDING_INSPECTION], true)) {
                throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$serial->serial_number} is {$serial->lifecycle_status->value}.", ['line' => $i]);
            }
        } elseif ($serial->current_inventory_location_id !== null && $req->type !== MovementType::REVERSAL) {
            throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$serial->serial_number} is already in stock elsewhere.", ['line' => $i]);
        }
    }

    private function value(MovementRequest $req, MovementLine $line, Item $item, InventoryLocation $from, InventoryLocation $to, string $lineId, int $i): string
    {
        $method = $this->policies->costingMethod($item);
        $fromBucket = $this->valuation->bucket($from);
        $toBucket = $this->valuation->bucket($to);

        if ($fromBucket === null) {          // external → owned
            $cost = $line->unitCost ?? ($method === \App\Domains\Catalog\Enums\CostingMethod::STANDARD ? $item->standard_cost : null)
                ?? $this->valuation->currentCost($line->itemId, $line->variantId, $toBucket);

            return $this->valuation->incoming($method, $line->itemId, $line->variantId, $toBucket, $line->quantity, (string) $cost, $lineId, $item->standard_cost);
        }
        if ($toBucket === null) {            // owned → external
            return $this->valuation->outgoing($method, $line->itemId, $line->variantId, $fromBucket, $line->quantity, $lineId,
                $line->fixedCost ? $line->unitCost : null, $item->standard_cost, $line->fifoLayerSourceLineId);
        }
        if ($fromBucket === $toBucket) {     // neutral internal move
            return $line->fixedCost && $line->unitCost !== null ? D::exact($line->unitCost, D::COST_SCALE)
                : $this->valuation->currentCost($line->itemId, $line->variantId, $fromBucket);
        }
        $cost = $this->valuation->outgoing($method, $line->itemId, $line->variantId, $fromBucket, $line->quantity, $lineId,
            $line->fixedCost ? $line->unitCost : null, $item->standard_cost);

        return $this->valuation->incoming($method, $line->itemId, $line->variantId, $toBucket, $line->quantity, $cost, $lineId, $item->standard_cost);
    }

    private function serialStatusAfter(MovementType $type, InventoryLocation $to, StockCondition $condition): SerialStatus
    {
        return match (true) {
            $to->location_type === InventoryLocationType::IN_TRANSIT => SerialStatus::IN_TRANSIT,
            $to->location_type->holdsOwnedStock() && $condition === StockCondition::RETURNED_PENDING_INSPECTION => SerialStatus::RETURNED_PENDING_INSPECTION,
            $to->location_type->holdsOwnedStock() => SerialStatus::IN_STOCK,
            $type === MovementType::SALE_DELIVERY => SerialStatus::SOLD,
            $to->location_type === InventoryLocationType::SCRAP => SerialStatus::SCRAPPED,
            $to->location_type === InventoryLocationType::INVENTORY_LOSS => SerialStatus::LOST,
            $to->location_type === InventoryLocationType::SUPPLIER => SerialStatus::REGISTERED,
            default => SerialStatus::ISSUED,
        };
    }
}
