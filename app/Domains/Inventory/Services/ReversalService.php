<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReversalStatus as S;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\StockMovementLine;
use App\Domains\Inventory\Models\StockMovementReversal;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Posting\PositionKey;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\InventoryLocation;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Sentinel;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ledger reversals (Chapter 07 §7.11): request (reason, lines) → approval by a different actor →
 * a `reversal` movement mirroring the original lines at their original cost. Blocked with
 * REVERSAL_BLOCKED_DOWNSTREAM when the stock has already moved on (issued, transferred, sold,
 * reserved). Idempotent per original line through `reversed_quantity`.
 */
final class ReversalService
{
    use ManagesDocuments;

    public function __construct(
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
    ) {}

    /**
     * @param  array<string, mixed>  $data  reason, reason_lookup_value_id?, lines?: [{line_id, quantity}] (default: everything outstanding)
     */
    public function request(string $movementId, UserAccount $actor, array $data): StockMovementReversal
    {
        return DB::transaction(function () use ($movementId, $actor, $data) {
            $movement = $this->lockDocument(StockMovement::class, $movementId);
            $this->authorizeWarehouse($actor, 'inventory.movement.reverse', $movement->warehouse_id);
            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A reversal needs a reason.', ['field' => 'reason']);
            }
            $lines = $this->resolveLines($movement, $data['lines'] ?? null);
            if (StockMovementReversal::query()->where('original_movement_id', $movement->id)->where('status', S::PENDING_APPROVAL->value)->exists()) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'A reversal of this movement is already awaiting approval.');
            }
            $this->assertNoDownstream($movement, $lines);
            $reversal = StockMovementReversal::query()->forceCreate([
                'document_number' => $this->nextNumber(DocumentType::STOCK_MOVEMENT, $movement->warehouse_id).'-R',
                'original_movement_id' => $movement->id, 'requested_by' => $actor->id, 'requested_at' => now(),
                'reason_lookup_value_id' => $data['reason_lookup_value_id'] ?? null, 'reason' => trim((string) $data['reason']),
                'lines' => $lines, 'status' => S::PENDING_APPROVAL,
            ]);
            $this->audit($actor, 'inventory.movement.reversal_requested', $reversal, null, ['movement_number' => $movement->movement_number, 'lines' => $lines], $reversal->reason);
            $this->publish('inventory.movement.reversal_requested', $reversal, ['movement_number' => $movement->movement_number]);

            return $reversal;
        });
    }

    public function approve(string $reversalId, UserAccount $actor, ?string $idempotencyKey, ?string $overrideReason = null): PostingOutcome
    {
        return $this->pipeline->run($actor, 'inventory.movement.reverse', $idempotencyKey, ['reversal_id' => $reversalId, 'override_reason' => $overrideReason],
            function (PostingContext $ctx) use ($reversalId, $actor, $overrideReason) {
                $reversal = $this->lockDocument(StockMovementReversal::class, $reversalId);
                $movement = $this->lockDocument(StockMovement::class, $reversal->original_movement_id);
                $this->authorizeWarehouse($actor, 'inventory.movement.reverse', $movement->warehouse_id);
                if ($reversal->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Reversal', $reversal->document_number);
                }
                $this->assertStatus($reversal, [S::PENDING_APPROVAL]);
                $this->enforceSod(DocumentType::STOCK_MOVEMENT, DocumentAction::APPROVE, [DocumentAction::REQUEST->value => [$reversal->requested_by],
                    DocumentAction::POST->value => array_filter([$movement->posting_actor_id])], $actor, $movement->id, null, null, $overrideReason);

                $lines = $this->resolveLines($movement, $reversal->lines);   // revalidate against what is still outstanding
                $this->assertNoDownstream($movement, $lines);
                $originals = StockMovementLine::query()->where('stock_movement_id', $movement->id)->whereIn('id', array_column($lines, 'line_id'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $mirror = [];
                foreach ($lines as $l) {
                    $o = $originals[$l['line_id']];
                    $mirror[] = new MovementLine($o->item_id, $o->item_variant_id, $o->destination_inventory_location_id, $o->destination_condition,
                        $o->source_inventory_location_id, $o->source_condition, $l['quantity'], lotId: $o->inventory_lot_id, serialId: $o->item_serial_id,
                        unitCost: (string) $o->unit_cost_snapshot, fixedCost: true, sourceLineType: 'stock_movement_line', sourceLineId: $o->id,
                        fifoLayerSourceLineId: $o->id, reversesLineId: $o->id);
                }
                $posted = $this->poster->post(new MovementRequest(MovementType::REVERSAL, DocumentType::STOCK_MOVEMENT, $reversal->id, $reversal->document_number,
                    (string) Str::uuid7(), $mirror, warehouseId: $movement->warehouse_id, counterpartyWarehouseId: $movement->counterparty_warehouse_id,
                    partnerId: $movement->partner_id, reversalOfMovementId: $movement->id, reasonLookupValueId: $reversal->reason_lookup_value_id,
                    reasonText: $reversal->reason, allowLockedLots: true, allowExpired: true), $ctx);

                $newLines = DB::table('stock_movement_lines')->where('stock_movement_id', $posted->id)->pluck('id', 'source_line_id');
                foreach ($lines as $l) {
                    DB::update('UPDATE stock_movement_lines SET reversed_quantity = reversed_quantity + ?, reversed_by_line_id = ?, updated_at = ? WHERE id = ?',
                        [$l['quantity'], $newLines[$l['line_id']] ?? null, now(), $l['line_id']]);
                }
                $fully = ! DB::table('stock_movement_lines')->where('stock_movement_id', $movement->id)->whereColumn('reversed_quantity', '<', 'quantity')->exists();
                DB::update('UPDATE stock_movements SET status = ?, updated_at = ? WHERE id = ?',
                    [($fully ? MovementStatus::REVERSED : MovementStatus::PARTIALLY_REVERSED)->value, now(), $movement->id]);
                $reversal->forceFill(['status' => S::POSTED, 'approved_by' => $actor->id, 'approved_at' => now(), 'reversal_movement_id' => $posted->id])->save();
                $ctx->audit('inventory.movement.reversed', 'stock_movements', $movement->id, ['status' => $movement->status->value],
                    ['status' => $fully ? 'reversed' : 'partially_reversed', 'reversal_movement' => $posted->movement_number], $reversal->reason);
                $ctx->publish('inventory.movement.reversed', 'stock_movements', $movement->id, ['movement_number' => $movement->movement_number,
                    'reversal_movement_number' => $posted->movement_number]);

                return new PostingOutcome(['reversal_id' => $reversal->id, 'document_number' => $reversal->document_number, 'status' => S::POSTED->value,
                    'original_status' => $fully ? 'reversed' : 'partially_reversed', 'stock_movement_id' => $posted->id, 'movement_number' => $posted->movement_number],
                    201, DocumentType::STOCK_MOVEMENT->value, $reversal->id);
            }, failureAudit: ['inventory.movement.reversal_failed', 'stock_movement_reversals', $reversalId]);
    }

    public function reject(string $reversalId, UserAccount $actor, ?string $reason): StockMovementReversal
    {
        return DB::transaction(function () use ($reversalId, $actor, $reason) {
            $reversal = $this->lockDocument(StockMovementReversal::class, $reversalId);
            $movement = StockMovement::query()->findOrFail($reversal->original_movement_id);
            $this->authorizeWarehouse($actor, 'inventory.movement.reverse', $movement->warehouse_id);
            $this->assertStatus($reversal, [S::PENDING_APPROVAL]);
            $reversal->forceFill(['status' => S::REJECTED, 'approved_by' => $actor->id, 'approved_at' => now()])->save();
            $this->audit($actor, 'inventory.movement.reversal_rejected', $reversal, null, null, $reason);

            return $reversal;
        });
    }

    /**
     * @param  array<int, array{line_id: string, quantity: string|int}>|null  $requested
     * @return array<int, array{line_id: string, quantity: string}>
     */
    private function resolveLines(StockMovement $movement, ?array $requested): array
    {
        if ($movement->movement_type === MovementType::REVERSAL) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'A reversal cannot itself be reversed; post a new document instead.');
        }
        if (! in_array($movement->status, [MovementStatus::POSTED, MovementStatus::PARTIALLY_REVERSED], true)) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, "Movement {$movement->movement_number} is {$movement->status->value}.");
        }
        $all = StockMovementLine::query()->where('stock_movement_id', $movement->id)->orderBy('line_no')->get()->keyBy('id');
        $requested ??= $all->map(fn ($l) => ['line_id' => $l->id, 'quantity' => D::sub($l->quantity, $l->reversed_quantity)])->values()->all();
        $out = [];
        foreach ($requested as $i => $r) {
            $line = $all->get($r['line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The line does not belong to this movement.', ['field' => "lines.$i.line_id"]);
            $qty = D::exact((string) $r['quantity']);
            $open = D::sub($line->quantity, $line->reversed_quantity);
            if (! D::isPositive($qty)) {
                continue;
            }
            if (D::gt($qty, $open)) {
                throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$line->line_no} has only ".D::trim($open).' left to reverse.', ['field' => "lines.$i.quantity"]);
            }
            if ($line->item_serial_id !== null && ! D::eq($qty, '1')) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'Serialized lines reverse one unit.', ['field' => "lines.$i.quantity"]);
            }
            $out[] = ['line_id' => $line->id, 'quantity' => $qty];
        }
        if ($out === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Nothing is left to reverse on this movement.');
        }

        return $out;
    }

    /**
     * The stock the original movement put somewhere must still be there and unreserved; otherwise
     * list the movements that took it further (Chapter 07 §7.11).
     *
     * @param  array<int, array{line_id: string, quantity: string}>  $lines
     */
    private function assertNoDownstream(StockMovement $movement, array $lines): void
    {
        $originals = StockMovementLine::query()->whereIn('id', array_column($lines, 'line_id'))->get()->keyBy('id');
        $locations = InventoryLocation::query()->whereIn('id', $originals->pluck('destination_inventory_location_id')->unique())->get()->keyBy('id');
        $need = [];
        foreach ($lines as $l) {
            $o = $originals[$l['line_id']];
            if (! $locations[$o->destination_inventory_location_id]->location_type->holdsOwnedStock()) {
                continue;   // stock went to an external location; reversing brings it back
            }
            $key = new PositionKey($o->item_id, $o->item_variant_id, $o->destination_inventory_location_id, $o->inventory_lot_id, $o->destination_condition);
            $need[$key->sortKey()] = ['key' => $key, 'qty' => D::add($need[$key->sortKey()]['qty'] ?? '0', $l['quantity']), 'line' => $o];
        }
        $blocked = [];
        foreach ($need as $n) {
            $balance = DB::table('inventory_balances')->where($n['key']->columns())->first(['quantity_on_hand', 'quantity_reserved']);
            $available = $balance === null ? '0' : D::sub((string) $balance->quantity_on_hand, (string) $balance->quantity_reserved);
            $serialMoved = $n['line']->item_serial_id !== null
                && DB::table('item_serials')->where('id', $n['line']->item_serial_id)->value('current_inventory_location_id') !== $n['key']->locationId;
            if (D::lt($available, $n['qty']) || $serialMoved) {
                $dependents = DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
                    ->where('ml.source_inventory_location_id', $n['key']->locationId)->where('ml.item_id', $n['key']->itemId)
                    ->where(fn ($q) => Sentinel::is($n['key']->lotId) ? $q->whereNull('ml.inventory_lot_id') : $q->where('ml.inventory_lot_id', $n['key']->lotId))
                    ->where('m.id', '<>', $movement->id)->where('m.posted_at', '>=', $movement->posted_at->format('Y-m-d H:i:s.uP'))->orderBy('m.posted_at')->limit(20)
                    ->get(['m.movement_number', 'm.movement_type', 'm.source_document_type', 'm.source_document_number', 'ml.quantity'])
                    ->map(fn ($d) => (array) $d)->all();
                $blocked[] = ['line_no' => $n['line']->line_no, 'required' => D::trim($n['qty']), 'available' => D::trim($available), 'dependents' => $dependents];
            }
        }
        if ($blocked !== []) {
            throw DomainException::of(ErrorCode::REVERSAL_BLOCKED_DOWNSTREAM, 'The stock from this movement has already been used further; reverse or return the dependent documents first.',
                ['lines' => $blocked]);
        }
    }
}
