<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotStatus;
use App\Domains\Inventory\Enums\RecallStatus as S;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Models\Recall;
use App\Domains\Inventory\Models\RecallLot;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Lot recalls (Chapter 07 §7.10): lock the lots everywhere (no issue, sale, transfer or
 * adjustment except scrap/returns), release their reservations, report where the stock is and who
 * received it, and restore the lock state on closure.
 */
final class RecallService
{
    use ManagesDocuments;

    public function __construct(private readonly ReservationService $reservations) {}

    /** @param  array{reason: string, lot_ids: array<int, string>}  $data */
    public function initiate(array $data, UserAccount $actor): Recall
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->authorizeWarehouse($actor, 'inventory.recall.initiate', null);
            $lotIds = array_values(array_unique((array) ($data['lot_ids'] ?? [])));
            if (trim((string) ($data['reason'] ?? '')) === '' || $lotIds === []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A recall needs a reason and at least one lot.', ['field' => $lotIds === [] ? 'lot_ids' : 'reason']);
            }
            sort($lotIds, SORT_STRING);
            $lots = InventoryLot::query()->whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get();
            if ($lots->count() !== count($lotIds)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'One or more lots do not exist.', ['field' => 'lot_ids']);
            }
            $recall = Recall::query()->forceCreate([
                'document_number' => $this->nextNumber(DocumentType::RECALL), 'reason' => trim($data['reason']), 'status' => S::INITIATED,
                'initiated_by' => $actor->id, 'initiated_at' => now(),
            ]);
            foreach ($lots as $lot) {
                RecallLot::query()->forceCreate(['recall_id' => $recall->id, 'inventory_lot_id' => $lot->id, 'previous_lock_status' => $lot->lock_status->value]);
                $lot->forceFill(['lock_status' => LotLockStatus::LOCKED_RECALL, 'status' => LotStatus::RECALLED])->save();
            }
            $open = StockReservation::query()->whereIn('inventory_lot_id', $lotIds)
                ->whereIn('status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])->orderBy('id')->get();
            foreach ($open as $reservation) {
                $this->reservations->release($reservation, $actor, "Lot recalled ({$recall->document_number})");
            }
            $this->transition($recall, S::IN_PROGRESS);
            $this->audit($actor, 'inventory.recall.initiated', $recall, null, ['lots' => $lots->pluck('lot_number')->all(), 'reservations_released' => $open->count()], $recall->reason);
            $this->publish('inventory.recall.initiated', $recall, ['document_number' => $recall->document_number, 'lot_ids' => $lotIds]);

            return $recall;
        });
    }

    /**
     * Where the recalled stock is and who received it.
     *
     * @return array{positions: array<int, array<string, mixed>>, recipients: array<int, array<string, mixed>>}
     */
    public function affected(string $recallId): array
    {
        $lotIds = RecallLot::query()->where('recall_id', $recallId)->pluck('inventory_lot_id')->all();
        $positions = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->leftJoin('storage_locations as sl', 'sl.id', '=', 'il.storage_location_id')->leftJoin('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
            ->whereIn('b.inventory_lot_id', $lotIds)->where('b.quantity_on_hand', '<>', 0)
            ->orderBy('w.code')->orderBy('sl.full_path')
            ->get(['l.lot_number', 'w.code as warehouse', 'il.location_type', 'sl.full_path as location', 'b.stock_condition', 'b.quantity_on_hand'])
            ->map(fn ($r) => (array) $r)->all();
        $recipients = DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->join('inventory_locations as dest', 'dest.id', '=', 'ml.destination_inventory_location_id')
            ->join('inventory_lots as l', 'l.id', '=', 'ml.inventory_lot_id')->leftJoin('partners as p', 'p.id', '=', 'm.partner_id')
            ->whereIn('ml.inventory_lot_id', $lotIds)->whereIn('dest.location_type', [InventoryLocationType::CUSTOMER->value, InventoryLocationType::PRODUCTION->value])
            ->groupBy('l.lot_number', 'p.id', 'p.code', 'p.name', 'm.source_document_type', 'm.source_document_number', 'm.posted_at')
            ->orderBy('m.posted_at')
            ->selectRaw('l.lot_number, p.code AS partner_code, p.name AS partner_name, m.source_document_type, m.source_document_number, m.posted_at, SUM(ml.quantity) AS quantity')
            ->get()->map(fn ($r) => (array) $r)->all();

        return ['positions' => $positions, 'recipients' => $recipients];
    }

    public function close(string $recallId, UserAccount $actor, ?string $note): Recall
    {
        return DB::transaction(function () use ($recallId, $actor, $note) {
            $recall = $this->lockDocument(Recall::class, $recallId);
            $this->authorizeWarehouse($actor, 'inventory.recall.close', null);
            $lotIds = RecallLot::query()->where('recall_id', $recall->id)->orderBy('inventory_lot_id')->pluck('inventory_lot_id')->all();
            foreach (InventoryLot::query()->whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get() as $lot) {
                $stillRecalled = RecallLot::query()->where('inventory_lot_id', $lot->id)->where('recall_id', '<>', $recall->id)
                    ->whereIn('recall_id', Recall::query()->where('status', '<>', S::CLOSED->value)->select('id'))->exists();
                if ($stillRecalled) {
                    continue;
                }
                $inTransit = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
                    ->where('b.inventory_lot_id', $lot->id)->where('il.location_type', InventoryLocationType::IN_TRANSIT->value)->where('b.quantity_on_hand', '>', 0)->exists();
                $lot->forceFill(['lock_status' => $inTransit ? LotLockStatus::LOCKED_IN_TRANSIT : LotLockStatus::UNLOCKED, 'status' => LotStatus::ACTIVE])->save();
            }
            $this->transition($recall, S::CLOSED, ['closed_by' => $actor->id, 'closed_at' => now(), 'closure_note' => $note]);
            $this->audit($actor, 'inventory.recall.closed', $recall, null, null, $note);
            $this->publish('inventory.recall.closed', $recall, ['document_number' => $recall->document_number]);

            return $recall;
        });
    }
}
