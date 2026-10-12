<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Posting\BalanceLocker;
use App\Domains\Inventory\Posting\PositionKey;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reservation lifecycle (Chapter 07 §7.6). Every operation runs inside the caller's transaction,
 * locks the stock position(s) in the global order, then the reservation rows, and revalidates.
 * Consumption happens inside InventoryPoster when stock is actually issued.
 */
final class ReservationService
{
    public function __construct(private readonly BalanceLocker $locker, private readonly PickingPlanner $planner) {}

    /**
     * Reserve a specific position.
     */
    public function reserve(
        ReservationReferenceType $type,
        string $referenceId,
        string $referenceLineId,
        PositionKey $key,
        ?string $warehouseId,
        string $quantity,
        ?UserAccount $actor,
        ?string $serialId = null,
        ?CarbonImmutable $expiresAt = null,
    ): StockReservation {
        $this->assertInTransaction();
        if ($key->condition !== StockCondition::AVAILABLE) {
            throw DomainException::of(ErrorCode::STOCK_INELIGIBLE, 'Only available stock can be reserved.');
        }
        $balance = $this->locker->lock([$key], [$key->locationId => $warehouseId])[$key->sortKey()];
        if ($balance->is_frozen) {
            throw DomainException::of(ErrorCode::POSITION_FROZEN, 'This stock position is frozen by an open stocktake.');
        }
        if (! Sentinel::is($key->lotId)) {
            $this->assertLotReservable(InventoryLot::query()->lockForUpdate()->findOrFail($key->lotId));
        }
        $available = D::sub($balance->quantity_on_hand, $balance->quantity_reserved);
        if (D::gt($quantity, $available)) {
            $item = Item::query()->find($key->itemId);
            throw DomainException::insufficientStock($item?->label() ?? $key->itemId, D::trim($quantity), D::trim($available));
        }
        if ($serialId !== null) {
            $serial = DB::table('item_serials')->where('id', $serialId)->lockForUpdate()->first();
            if ($serial === null || $serial->current_inventory_location_id !== $key->locationId || $serial->lifecycle_status !== SerialStatus::IN_STOCK->value) {
                throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, 'The serial is not available at this position.');
            }
            DB::table('item_serials')->where('id', $serialId)->update(['lifecycle_status' => SerialStatus::RESERVED->value, 'updated_at' => now()]);
        }

        $reservation = new StockReservation([
            'reference_type' => $type, 'reference_id' => $referenceId, 'reference_line_id' => $referenceLineId,
            'inventory_balance_id' => $balance->id, 'item_id' => $key->itemId, 'item_variant_id' => $key->variantId,
            'inventory_location_id' => $key->locationId, 'inventory_lot_id' => $key->lotId, 'item_serial_id' => $serialId,
            'warehouse_id' => $warehouseId, 'quantity_reserved' => D::exact($quantity), 'quantity_consumed' => '0',
            'quantity_released' => '0', 'status' => ReservationStatus::ACTIVE, 'expires_at' => $expiresAt, 'created_by' => $actor?->id,
        ]);
        $reservation->save();

        DB::update('UPDATE inventory_balances SET quantity_reserved = quantity_reserved + ?, version = version + 1, updated_at = ? WHERE id = ?',
            [D::exact($quantity), now(), $balance->id]);

        return $reservation;
    }

    /**
     * Reserve across eligible positions of a warehouse following the item's removal strategy.
     * Plans without locks, locks every planned position, then revalidates; on a changed picture
     * it re-plans (bounded).
     *
     * @return array<int, StockReservation>
     */
    public function reserveFromWarehouse(
        ReservationReferenceType $type,
        string $referenceId,
        string $referenceLineId,
        Item $item,
        ?string $variantId,
        string $warehouseId,
        string $quantity,
        ?UserAccount $actor,
        bool $allowPartial = false,
        ?string $lotId = null,
        ?CarbonImmutable $expiresAt = null,
    ): array {
        $this->assertInTransaction();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $plan = $this->planner->plan($item, $variantId, $warehouseId, $quantity, $lotId);
            $planned = D::sum(array_column($plan, 'take'));
            if (D::lt($planned, $quantity) && ! $allowPartial) {
                throw DomainException::insufficientStock($item->label(), D::trim($quantity), D::trim($planned), ['warehouse_id' => $warehouseId]);
            }
            $keys = array_map(static fn (array $p) => new PositionKey($item->id, $variantId, $p['inventory_location_id'], $p['lot_id'], StockCondition::AVAILABLE), $plan);
            $locked = $this->locker->lock($keys, array_fill_keys(array_column($plan, 'inventory_location_id'), $warehouseId));

            $stillValid = true;
            foreach ($plan as $i => $p) {
                $b = $locked[$keys[$i]->sortKey()];
                if (D::gt($p['take'], D::sub($b->quantity_on_hand, $b->quantity_reserved)) || $b->is_frozen) {
                    $stillValid = false;
                    break;
                }
            }
            if (! $stillValid) {
                continue;
            }
            $out = [];
            foreach ($plan as $i => $p) {
                $out[] = $this->reserve($type, $referenceId, $referenceLineId, $keys[$i], $warehouseId, $p['take'], $actor, null, $expiresAt);
            }

            return $out;
        }
        throw DomainException::retriesExhausted();
    }

    /** Release the outstanding quantity (or part of it). Idempotent for already-closed reservations. */
    public function release(StockReservation $reservation, ?UserAccount $actor, ?string $reason = null, ?string $quantity = null): void
    {
        $this->assertInTransaction();
        $this->locker->lockByIds([$reservation->inventory_balance_id]);
        /** @var StockReservation $r */
        $r = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
        if (! $r->isOpen()) {
            return;
        }
        $outstanding = $r->outstanding();
        $release = $quantity === null ? $outstanding : D::min(D::exact($quantity), $outstanding);
        if (! D::isPositive($release)) {
            return;
        }
        $released = D::add($r->quantity_released, $release);
        $left = D::sub(D::sub($r->quantity_reserved, $r->quantity_consumed), $released);
        $r->forceFill([
            'quantity_released' => $released,
            'status' => D::isZero($left) ? (D::isPositive($r->quantity_consumed) ? ReservationStatus::CONSUMED : ReservationStatus::RELEASED) : $r->status,
            'released_by' => $actor?->id,
            'released_at' => D::isZero($left) ? now() : $r->released_at,
            'release_reason' => $reason,
            'version' => $r->version + 1,
        ])->save();

        DB::update('UPDATE inventory_balances SET quantity_reserved = quantity_reserved - ?, version = version + 1, updated_at = ? WHERE id = ?',
            [$release, now(), $r->inventory_balance_id]);

        if ($r->item_serial_id !== null && D::isZero($left)) {
            DB::table('item_serials')->where('id', $r->item_serial_id)->where('lifecycle_status', SerialStatus::RESERVED->value)
                ->update(['lifecycle_status' => SerialStatus::IN_STOCK->value, 'updated_at' => now()]);
        }
    }

    /** Release every open reservation held for a document (cancellation, closure). */
    public function releaseForReference(ReservationReferenceType $type, string $referenceId, ?UserAccount $actor, string $reason, ?string $referenceLineId = null): int
    {
        $open = StockReservation::query()->where('reference_type', $type->value)->where('reference_id', $referenceId)
            ->when($referenceLineId !== null, fn ($q) => $q->where('reference_line_id', $referenceLineId))
            ->whereIn('status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])
            ->orderBy('id')->get();
        // Lock all affected positions first (global order), then release one by one.
        $this->locker->lockByIds($open->pluck('inventory_balance_id')->all());
        foreach ($open as $reservation) {
            $this->release($reservation, $actor, $reason);
        }

        return $open->count();
    }

    /**
     * Open reservations of a document line keyed by balance id, for consumption by an issue.
     *
     * @return array<string, StockReservation>
     */
    public function openForLine(ReservationReferenceType $type, string $referenceLineId): array
    {
        return StockReservation::query()->where('reference_type', $type->value)->where('reference_line_id', $referenceLineId)
            ->whereIn('status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])
            ->orderBy('id')->get()->keyBy('inventory_balance_id')->all();
    }

    /**
     * Make sure `$quantity` of a document line is reserved at exactly the picked position and return
     * the reservations to consume (id → qty). Reservations the line holds at the position are used
     * first; a shortfall is moved from the line's reservations elsewhere (released) to the picked
     * position (reserved). Without any reservation for the line, nothing is reserved and `[]` is
     * returned unless `$reserveUnreserved` — the posting then draws on unreserved stock.
     *
     * @return array<string, string>
     */
    public function secureForPosition(
        ReservationReferenceType $type,
        string $referenceId,
        string $referenceLineId,
        PositionKey $key,
        ?string $warehouseId,
        string $quantity,
        ?UserAccount $actor,
        ?string $serialId = null,
        bool $reserveUnreserved = false,
    ): array {
        $this->assertInTransaction();
        $open = StockReservation::query()->where('reference_type', $type->value)->where('reference_line_id', $referenceLineId)
            ->whereIn('status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])
            ->orderBy('id')->get();
        if ($open->isEmpty() && ! $reserveUnreserved) {
            return [];
        }
        $remaining = D::exact($quantity);
        $consume = [];
        foreach ($open as $r) {
            if (! D::isPositive($remaining)) {
                break;
            }
            $samePosition = $r->inventory_location_id === $key->locationId && $r->inventory_lot_id === $key->lotId
                && $r->item_variant_id === $key->variantId && ($r->item_serial_id === null || $r->item_serial_id === $serialId);
            if ($samePosition) {
                $take = D::min($remaining, $r->outstanding());
                $consume[$r->id] = $take;
                $remaining = D::sub($remaining, $take);
            }
        }
        if (D::isPositive($remaining)) {
            $toMove = $remaining;
            foreach ($open as $r) {
                if (! D::isPositive($toMove) || isset($consume[$r->id])) {
                    continue;
                }
                $release = D::min($toMove, $r->outstanding());
                $this->release($r, $actor, 'Re-pointed to picked position', $release);
                $toMove = D::sub($toMove, $release);
            }
            $reservation = $this->reserve($type, $referenceId, $referenceLineId, $key, $warehouseId, $remaining, $actor, $serialId);
            $consume[$reservation->id] = $remaining;
        }

        return $consume;
    }

    /** Expire stale reservations (scheduler; system actor). */
    public function expireStale(int $limit = 500): int
    {
        $ids = StockReservation::query()->whereIn('status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])
            ->whereNotNull('expires_at')->where('expires_at', '<', now())->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                $r = StockReservation::query()->find($id);
                if ($r !== null && $r->isOpen()) {
                    $this->release($r, null, 'Expired');
                    StockReservation::query()->whereKey($id)->where('status', ReservationStatus::RELEASED->value)->update(['status' => ReservationStatus::EXPIRED->value]);
                    $count++;
                }
            });
        }

        return $count;
    }

    private function assertLotReservable(InventoryLot $lot): void
    {
        if ($lot->lock_status !== LotLockStatus::UNLOCKED) {
            throw DomainException::of(ErrorCode::LOT_LOCKED, "Lot {$lot->lot_number} is locked.");
        }
        if (! in_array($lot->qc_status, [LotQcStatus::RELEASED, LotQcStatus::CONDITIONAL], true)) {
            throw DomainException::of(ErrorCode::LOT_NOT_RELEASED, "Lot {$lot->lot_number} has not been released by quality control.");
        }
        if ($lot->expiry_date !== null && $lot->expiry_date->lte(now()->startOfDay())) {
            throw DomainException::of(ErrorCode::STOCK_INELIGIBLE, "Lot {$lot->lot_number} has expired.");
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Reservation changes must run inside a transaction.');
        }
    }

    public static function newId(): string
    {
        return (string) Str::uuid7();
    }
}
