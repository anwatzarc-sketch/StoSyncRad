<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Services;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Str;

/**
 * Receipt from production (Chapter 08 §8.7, feature.production_store): finished goods enter stock
 * from the `production` virtual location at the given cost; lots/serials are registered. No BOM.
 */
final class ProductionReceiptService
{
    use ManagesDocuments;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly LotService $lots,
        private readonly LineQuantities $lines,
    ) {}

    /** @param  array{warehouse_id: string, reference?: ?string, lines: array<int, array<string, mixed>>}  $data */
    public function post(UserAccount $actor, ?string $idempotencyKey, array $data): PostingOutcome
    {
        if (! $this->config->feature('feature.production_store')) {
            throw DomainException::featureDisabled('feature.production_store');
        }

        return $this->pipeline->run($actor, 'fulfillment.production.receive', $idempotencyKey, $data, function (PostingContext $ctx) use ($actor, $data) {
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id'] ?? '');
            $this->authorizeWarehouse($actor, 'fulfillment.production.receive', $warehouse->id);
            $warehouse->assertOperational(inbound: true);
            $warehouse->assertCapability(WarehouseCapability::RECEIVE_FROM_PRODUCTION);
            $docId = (string) Str::uuid7();
            $number = $this->nextNumber(DocumentType::STOCK_MOVEMENT, $warehouse->id);
            $production = $this->locations->virtual(InventoryLocationType::PRODUCTION)->id;
            $out = [];
            foreach (array_values((array) ($data['lines'] ?? [])) as $i => $l) {
                $item = $this->lines->item((string) $l['item_id']);
                $storage = StorageLocation::query()->findOrFail($l['storage_location_id']);
                if ($storage->warehouse_id !== $warehouse->id) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The location belongs to another warehouse.', ['field' => "lines.$i.storage_location_id"]);
                }
                $lotId = ($item->tracking_mode->requiresLot() || ! empty($l['lot_number']))
                    ? $this->lots->resolveForReceipt($item, $l['item_variant_id'] ?? null, $l['lot_number'] ?? null, null, $l['manufacture_date'] ?? null,
                        $l['expiry_date'] ?? null, 'production_receipt', $docId, $actor->id)->id : null;
                $to = $this->locations->forStorageLocation($storage->id)->id;
                $condition = StockCondition::from($l['stock_condition'] ?? StockCondition::AVAILABLE->value);
                $serials = (array) ($l['serial_numbers'] ?? []);
                if ($item->tracking_mode->requiresSerial()) {
                    if (count($serials) !== (int) D::of($l['quantity'])->toInt()) {
                        throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'One serial per unit is required.', ['field' => "lines.$i.serial_numbers"]);
                    }
                    foreach ($serials as $sn) {
                        $serial = $this->lots->resolveSerial($item, $l['item_variant_id'] ?? null, (string) $sn, $lotId);
                        $out[] = new MovementLine($item->id, $l['item_variant_id'] ?? null, $production, StockCondition::AVAILABLE, $to, $condition, '1',
                            lotId: $lotId, serialId: $serial->id, unitCost: (string) $l['unit_cost'], sourceLineType: 'production_receipt', sourceLineId: null);
                    }

                    continue;
                }
                $out[] = new MovementLine($item->id, $l['item_variant_id'] ?? null, $production, StockCondition::AVAILABLE, $to, $condition, D::exact((string) $l['quantity']),
                    lotId: $lotId, unitCost: (string) $l['unit_cost'], sourceLineType: 'production_receipt');
            }
            if ($out === []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add at least one line.');
            }
            $movement = $this->poster->post(new MovementRequest(MovementType::PRODUCTION_RECEIPT, DocumentType::STOCK_MOVEMENT, $docId, $number, (string) Str::uuid7(),
                $out, warehouseId: $warehouse->id, reasonText: $data['reference'] ?? null), $ctx);
            $ctx->audit('fulfillment.production.received', 'stock_movements', $movement->id, null, ['movement_number' => $movement->movement_number]);

            return new PostingOutcome(['document_number' => $number, 'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201);
        }, failureAudit: ['fulfillment.production.receive_failed', 'warehouses', (string) ($data['warehouse_id'] ?? Str::uuid7())]);
    }
}
