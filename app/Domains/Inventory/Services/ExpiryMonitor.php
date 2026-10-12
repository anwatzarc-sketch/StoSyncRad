<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Enums\LotStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Expiry monitor (Chapter 07 §7.7), run by the scheduler; idempotent per warehouse and day.
 * At expiry, available stock of the lot moves to the `expired` condition (when
 * `stock.expiry.block_on_expiry`), its reservations are released, and alerts are raised at the
 * configured horizons.
 */
final class ExpiryMonitor
{
    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly ReservationService $reservations,
        private readonly Outbox $outbox,
    ) {}

    /** @return array{expired_positions: int, alerts: int} */
    public function run(): array
    {
        $today = now()->toDateString();
        $expired = 0;
        $rows = DB::table('inventory_balances as b')->join('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
            ->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
            ->where('b.stock_condition', StockCondition::AVAILABLE->value)->where('b.quantity_on_hand', '>', 0)
            ->whereNotNull('l.expiry_date')->where('l.expiry_date', '<=', $today)->where('il.location_type', 'physical')
            ->where('b.is_frozen', false)
            ->orderBy('b.warehouse_id')->orderBy('b.id')
            ->get(['b.id', 'b.warehouse_id', 'b.item_id', 'b.item_variant_id', 'b.inventory_location_id', 'b.inventory_lot_id', 'l.lot_number', 'l.item_id as lot_item']);

        foreach ($rows->groupBy('warehouse_id') as $warehouseId => $positions) {
            $positions = $positions->filter(fn ($p) => $this->config->bool('stock.expiry.block_on_expiry',
                ['category' => DB::table('items')->where('id', $p->item_id)->value('category_id')]));
            if ($positions->isEmpty()) {
                continue;
            }
            $key = $this->dailyKey('expiry', $warehouseId, $today);
            $this->pipeline->run(null, 'inventory.expiry.monitor', $key, ['warehouse_id' => $warehouseId, 'date' => $today],
                function (PostingContext $ctx) use ($positions, $warehouseId, $today) {
                    $lotIds = $positions->pluck('inventory_lot_id')->unique()->sort()->values()->all();
                    foreach (StockReservation::query()->whereIn('inventory_lot_id', $lotIds)->where('warehouse_id', $warehouseId)
                        ->whereIn('status', [ReservationStatus::ACTIVE->value, ReservationStatus::PARTIALLY_CONSUMED->value])->orderBy('id')->get() as $r) {
                        $this->reservations->release($r, null, 'Lot expired');
                    }
                    $lines = [];
                    foreach ($positions as $p) {
                        $qty = (string) DB::table('inventory_balances')->where('id', $p->id)->value('quantity_on_hand');
                        if (D::isPositive($qty)) {
                            $lines[] = new MovementLine($p->item_id, Sentinel::toNull($p->item_variant_id), $p->inventory_location_id, StockCondition::AVAILABLE,
                                $p->inventory_location_id, StockCondition::EXPIRED, D::exact($qty), lotId: $p->inventory_lot_id, sourceLineType: 'expiry_monitor');
                        }
                    }
                    $movement = $lines === [] ? null : $this->poster->post(new MovementRequest(MovementType::CONDITION_CHANGE, DocumentType::STOCK_MOVEMENT,
                        $this->dailyKey('expiry-doc', $warehouseId, $today), 'EXP-'.str_replace('-', '', $today), $this->dailyKey('expiry-event', $warehouseId, $today),
                        $lines, warehouseId: $warehouseId, reasonText: 'Lot expired', allowLockedLots: true, allowExpired: true), $ctx);
                    DB::table('inventory_lots')->whereIn('id', $lotIds)->where('status', LotStatus::ACTIVE->value)
                        ->update(['status' => LotStatus::EXPIRED->value, 'updated_at' => now()]);
                    $ctx->publish('inventory.lot.expired', 'warehouses', $warehouseId, ['lot_ids' => $lotIds, 'movement_number' => $movement?->movement_number]);

                    return new PostingOutcome(['movement_number' => $movement?->movement_number], 200);
                });
            $expired += $positions->count();
        }

        $alerts = 0;
        foreach ((array) $this->config->list('stock.expiry.alert_horizons_days') as $days) {
            $date = now()->addDays((int) $days)->toDateString();
            foreach (DB::table('inventory_lots')->where('expiry_date', $date)->where('status', LotStatus::ACTIVE->value)->get(['id', 'item_id', 'lot_number']) as $lot) {
                $already = DB::table('outbox_events')->where('event_type', 'inventory.lot.expiry_warning')->where('aggregate_id', $lot->id)
                    ->whereRaw("payload->>'horizon_days' = ?", [(string) $days])->exists();
                if (! $already) {
                    $this->outbox->publish('inventory.lot.expiry_warning', 'inventory_lots', $lot->id,
                        ['lot_number' => $lot->lot_number, 'item_id' => $lot->item_id, 'expiry_date' => $date, 'horizon_days' => (int) $days]);
                    $alerts++;
                }
            }
        }

        return ['expired_positions' => $expired, 'alerts' => $alerts];
    }

    /** Deterministic UUID-shaped key so a rerun on the same day replays instead of posting twice. */
    private function dailyKey(string $purpose, string $scope, string $date): string
    {
        $h = md5("$purpose|$scope|$date");

        return sprintf('%s-%s-7%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
