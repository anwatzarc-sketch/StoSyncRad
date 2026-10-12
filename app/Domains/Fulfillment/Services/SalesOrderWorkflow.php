<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Billing\Services\CreditService;
use App\Domains\Catalog\Enums\OutOfStockBehavior;
use App\Domains\Catalog\Models\Item;
use App\Domains\Fulfillment\Enums\PickListStatus;
use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Enums\SalesOrderStatus as S;
use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Models\PickList;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\SalesOrderLine;
use App\Domains\Fulfillment\Models\Shipment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationPolicy;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock-side sales orders (Chapter 08 §8.8–8.9): intake (UI or API with SKU/barcode mapping and
 * external-id dedupe), confirmation with reservation, counter delivery posting and cancellation.
 * E-commerce shipping is handled by ShipmentWorkflow, which reports deliveries back here.
 */
final class SalesOrderWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly ReservationService $reservations,
        private readonly PickedStock $picked,
        private readonly Access $access,
    ) {}

    /** @param  array<string, mixed>  $data  validated against SalesOrderSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): SalesOrder
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $channel = SalesChannel::from($data['channel'] ?? SalesChannel::RETAIL_COUNTER->value);
                $this->assertChannelEnabled($channel);
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                $this->authorizeIntake($actor, $channel, $warehouse->id);
                $warehouse->assertOperational();
                $warehouse->assertCapability($channel === SalesChannel::ECOMMERCE ? WarehouseCapability::FULFILL_ECOMMERCE : WarehouseCapability::SELL_RETAIL);
                if (! empty($data['external_order_id'])) {
                    if (empty($data['external_source'])) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'An external order id needs its external source.', ['field' => 'external_source']);
                    }
                    $existing = SalesOrder::query()->where('external_source', $data['external_source'])->where('external_order_id', $data['external_order_id'])->first();
                    if ($existing !== null) {
                        throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "Order {$data['external_order_id']} from {$data['external_source']} was already received as {$existing->document_number}.",
                            ['sales_order_id' => $existing->id, 'document_number' => $existing->document_number]);
                    }
                }
                $order = new SalesOrder;
                $order->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::SALES_ORDER, $warehouse->id),
                    'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'channel' => $channel,
                    'customer_partner_id' => $this->customer($data['customer_partner_id'] ?? null, $warehouse->branch_id),
                    'external_source' => $data['external_source'] ?? null, 'external_order_id' => $data['external_order_id'] ?? null,
                    'status' => S::DRAFT, 'created_by' => $actor->id, 'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $order = $this->lockDocument(SalesOrder::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.sales_order.update', $order->warehouse_id);
                $this->expectVersion($order, $data['version'] ?? null);
                $this->assertStatus($order, [S::DRAFT]);
                $order->forceFill(['version' => $order->version + 1]);
            }
            $order->fill(array_intersect_key($data, array_flip(['shipping_address', 'currency', 'payment_reference', 'document_date', 'priority', 'reference', 'notes'])));
            $order->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                $this->writeLines($order, $data['lines']);
            }
            $this->audit($actor, $id === null ? 'fulfillment.sales_order.created' : 'fulfillment.sales_order.updated', $order, null,
                ['document_number' => $order->document_number, 'channel' => $order->channel->value]);

            return $order->refresh();
        });
    }

    /**
     * Customer Portal intake (CP-1.1 section 5.3). The customer, warehouse and prices are decided by the
     * portal services on the server, never taken from the request. Idempotent on (external_source,
     * external_order_id): a retried submission returns the order created the first time. The order
     * stays a draft until staff confirm it; reservation, picking and delivery are the normal actions.
     *
     * @param  array{warehouse_id: string, external_source: string, external_order_id: string, currency?: ?string, shipping_address?: ?array,
     *     reference?: ?string, notes?: ?string, lines: array<int, array{item_id: string, quantity: string, unit_price: string}>}  $data
     */
    public function intakeForCustomer(array $data, Partner $customer, UserAccount $account): SalesOrder
    {
        $existing = fn () => SalesOrder::query()->where('external_source', $data['external_source'])->where('external_order_id', $data['external_order_id'])->first();
        $found = $existing();
        if ($found !== null) {
            return $found->customer_partner_id === $customer->id ? $found
                : throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'This order reference is already used.');
        }
        try {
            return DB::transaction(function () use ($data, $customer, $account) {
                $this->assertChannelEnabled(SalesChannel::CUSTOMER_PORTAL);
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                $warehouse->assertOperational();
                $order = new SalesOrder;
                $order->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::SALES_ORDER, $warehouse->id),
                    'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'channel' => SalesChannel::CUSTOMER_PORTAL,
                    'customer_partner_id' => $customer->id, 'external_source' => $data['external_source'], 'external_order_id' => $data['external_order_id'],
                    'status' => S::DRAFT, 'created_by' => $account->id, 'updated_by' => $account->id, 'document_date' => now()->toDateString(),
                    'currency' => $data['currency'] ?? null, 'shipping_address' => $data['shipping_address'] ?? null,
                    'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
                ])->save();
                $this->writeLines($order, $data['lines']);
                $this->audit($account, 'fulfillment.sales_order.created', $order, null, ['document_number' => $order->document_number, 'channel' => $order->channel->value,
                    'source' => $data['external_source']]);
                $this->publish('fulfillment.sales_order.placed', $order, ['document_number' => $order->document_number, 'warehouse_id' => $order->warehouse_id,
                    'customer_partner_id' => $customer->id, 'customer_name' => $customer->name]);

                return $order->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            return $existing() ?? throw $e;   // a concurrent retry won the race
        }
    }

    /** A customer may withdraw an order staff have not confirmed yet (nothing is reserved). */
    public function cancelByCustomer(string $id, Partner $customer, UserAccount $account, ?string $reason): SalesOrder
    {
        return DB::transaction(function () use ($id, $customer, $account, $reason) {
            $order = $this->lockDocument(SalesOrder::class, $id);
            if ($order->customer_partner_id !== $customer->id) {
                throw DomainException::notFound('sales order');
            }
            if ($order->status !== S::DRAFT) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This order is already being processed. Please contact us to change it.',
                    ['status' => $order->status->value]);
            }
            $this->transition($order, S::CANCELLED, ['cancelled_by' => $account->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($account, 'fulfillment.sales_order.cancelled', $order, null, ['by' => 'customer'], $reason);
            $this->publish('fulfillment.sales_order.cancelled', $order, ['document_number' => $order->document_number, 'external_order_id' => $order->external_order_id,
                'customer_partner_id' => $customer->id, 'warehouse_id' => $order->warehouse_id]);

            return $order;
        });
    }

    /** Confirm; reserves stock per `stock.reservation.policy`. E-commerce orders honour the backorder policy. */
    public function confirm(string $id, UserAccount $actor, ?int $version = null, ?string $overrideReason = null): SalesOrder
    {
        return DB::transaction(function () use ($id, $actor, $version, $overrideReason) {
            $order = $this->lockDocument(SalesOrder::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.sales_order.confirm', $order->warehouse_id);
            $this->expectVersion($order, $version);
            $this->assertChannelEnabled($order->channel);
            // Customer credit limit (Billing): above it only with billing.credit.override and a reason.
            app(CreditService::class)->assertCanConfirm($order, $actor, $overrideReason);
            $lines = SalesOrderLine::query()->where('sales_order_id', $order->id)->orderBy('line_no')->get();
            if ($lines->isEmpty()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The order has no lines.');
            }
            $this->transition($order, S::CONFIRMED, ['confirmed_by' => $actor->id, 'confirmed_at' => now()]);

            $policy = ReservationPolicy::from((string) $this->config->get('stock.reservation.policy', ['warehouse' => $order->warehouse_id]));
            if ($policy === ReservationPolicy::ON_APPROVAL) {
                $allowPartial = $order->channel !== SalesChannel::ECOMMERCE
                    || OutOfStockBehavior::from((string) $this->config->get('fulfillment.ecommerce.backorder_policy')) !== OutOfStockBehavior::BLOCK;
                $days = $this->config->int('stock.reservation.auto_release_days', ['warehouse' => $order->warehouse_id]);
                $complete = true;
                foreach ($lines as $line) {
                    $made = $this->reservations->reserveFromWarehouse(ReservationReferenceType::SALES_ORDER, $order->id, $line->id, $this->lines->item($line->item_id, false),
                        $line->item_variant_id, $order->warehouse_id, D::exact($line->base_quantity), $actor, $allowPartial,
                        expiresAt: $days > 0 ? now()->addDays($days)->toImmutable() : null);
                    $complete = $complete && D::eq(D::sum(array_map(fn ($r) => $r->quantity_reserved, $made)), $line->base_quantity);
                }
                if ($complete) {
                    $this->transition($order, S::RESERVED);
                }
            }
            $this->audit($actor, 'fulfillment.sales_order.confirmed', $order);
            $this->publish('fulfillment.sales_order.confirmed', $order, ['document_number' => $order->document_number, 'channel' => $order->channel->value,
                'warehouse_id' => $order->warehouse_id, 'external_order_id' => $order->external_order_id]);

            return $order;
        });
    }

    /**
     * Counter/wholesale delivery (PostSaleDeliveryAction): posts the picked stock to the customer.
     *
     * @param  array<string, mixed>  $data  validated against PickedLinesSchema
     */
    public function deliver(string $id, UserAccount $actor, ?string $idempotencyKey, array $data): PostingOutcome
    {
        return $this->pipeline->run($actor, 'fulfillment.sales_order.deliver', $idempotencyKey, ['sales_order_id' => $id] + $data,
            function (PostingContext $ctx) use ($id, $actor, $data) {
                $order = $this->lockDocument(SalesOrder::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.sales_order.deliver', $order->warehouse_id);
                $this->expectVersion($order, $data['version'] ?? null);
                $this->assertChannelEnabled($order->channel);
                if ($order->channel === SalesChannel::ECOMMERCE) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'E-commerce orders are delivered through shipments (pack → hand over).');
                }
                $this->assertStatus($order, [S::CONFIRMED, S::RESERVED, S::PICKING, S::PACKED, S::PARTIALLY_DELIVERED]);
                Warehouse::query()->findOrFail($order->warehouse_id)->assertOperational();

                $orderLines = SalesOrderLine::query()->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $customer = $this->locations->virtual(InventoryLocationType::CUSTOMER)->id;
                $delivered = [];
                $movementLines = [];
                foreach (array_values($data['lines']) as $i => $picked) {
                    $ol = $orderLines->get($picked['order_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown order line.', ['field' => "lines.$i.order_line_id"]);
                    $qty = D::exact($picked['quantity']);
                    $outstanding = D::sub($ol->base_quantity, D::add($ol->delivered_base_quantity, $delivered[$ol->id] ?? '0'));
                    if (D::gt($qty, $outstanding)) {
                        throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$ol->line_no} exceeds the ordered quantity.", ['field' => "lines.$i.quantity", 'outstanding' => D::trim($outstanding)]);
                    }
                    $delivered[$ol->id] = D::add($delivered[$ol->id] ?? '0', $qty);
                    $item = $this->lines->item($ol->item_id, false);
                    foreach ($this->picked->expand($item, $order->warehouse_id, $picked['storage_location_id'], $picked['inventory_lot_id'] ?? null,
                        $picked['serial_numbers'] ?? null, $qty, "lines.$i") as $row) {
                        $consume = $this->picked->secure(ReservationReferenceType::SALES_ORDER, $order->id, $ol->id, $ol->item_id, $ol->item_variant_id, $order->warehouse_id, $row, $actor);
                        $movementLines[] = new MovementLine($ol->item_id, $ol->item_variant_id, $this->picked->location($row['storage_location_id']), StockCondition::AVAILABLE,
                            $customer, StockCondition::AVAILABLE, $row['quantity'], lotId: $row['lot_id'], serialId: $row['serial_id'],
                            sourceLineType: 'sales_order_line', sourceLineId: $ol->id, consumeReservations: $consume);
                    }
                }
                $movement = $this->poster->post(new MovementRequest(MovementType::SALE_DELIVERY, DocumentType::SALES_ORDER, $order->id, $order->document_number,
                    (string) Str::uuid7(), $movementLines, warehouseId: $order->warehouse_id, partnerId: $order->customer_partner_id), $ctx);
                $this->recordDelivered($order, $delivered, $actor);
                $ctx->audit('fulfillment.sales_order.delivered', 'sales_orders', $order->id, null, ['movement_number' => $movement->movement_number, 'status' => $order->status->value]);
                $ctx->publish('fulfillment.sales_order.delivered', 'sales_orders', $order->id, ['document_number' => $order->document_number, 'status' => $order->status->value]);

                return new PostingOutcome(['sales_order_id' => $order->id, 'document_number' => $order->document_number, 'status' => $order->status->value,
                    'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, DocumentType::SALES_ORDER->value, $order->id);
            }, failureAudit: ['fulfillment.sales_order.deliver_failed', 'sales_orders', $id]);
    }

    /**
     * Apply delivered quantities (counter posting or shipment posting) and derive the order status.
     *
     * @param  array<string, string>  $delivered  order line id → base qty
     */
    public function recordDelivered(SalesOrder $order, array $delivered, ?UserAccount $actor): void
    {
        foreach ($delivered as $lineId => $qty) {
            DB::update('UPDATE sales_order_lines SET delivered_base_quantity = delivered_base_quantity + ?, updated_at = ? WHERE id = ?', [$qty, now(), $lineId]);
        }
        $lines = SalesOrderLine::query()->where('sales_order_id', $order->id)->get();
        $all = $lines->every(fn ($l) => D::gte($l->delivered_base_quantity, $l->base_quantity));
        $target = $all ? S::DELIVERED : S::PARTIALLY_DELIVERED;
        if ($order->status !== $target) {
            $this->transition($order, $target);
        }
        if ($all) {
            $this->reservations->releaseForReference(ReservationReferenceType::SALES_ORDER, $order->id, $actor, 'Order delivered');
        }
    }

    /** Cancel before any delivery; releases reservations and cancels open pick lists and unshipped shipments. */
    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): SalesOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $order = $this->lockDocument(SalesOrder::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.sales_order.cancel', $order->warehouse_id);
            $this->expectVersion($order, $version);
            $shipped = Shipment::query()->where('sales_order_id', $order->id)->whereNotIn('status', [ShipmentStatus::PACKED->value, ShipmentStatus::CANCELLED->value])->exists();
            if ($shipped) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'A shipment has already been handed to the carrier; use a customer return instead.');
            }
            $this->transition($order, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            Shipment::query()->where('sales_order_id', $order->id)->where('status', ShipmentStatus::PACKED->value)
                ->update(['status' => ShipmentStatus::CANCELLED->value, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            PickList::query()->where('source_type', 'sales_order')->where('source_id', $order->id)
                ->whereIn('status', [PickListStatus::OPEN->value, PickListStatus::IN_PROGRESS->value])
                ->update(['status' => PickListStatus::CANCELLED->value, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->reservations->releaseForReference(ReservationReferenceType::SALES_ORDER, $order->id, $actor, 'Order cancelled');
            $this->audit($actor, 'fulfillment.sales_order.cancelled', $order, null, null, $reason);
            $this->publish('fulfillment.sales_order.cancelled', $order, ['document_number' => $order->document_number, 'external_order_id' => $order->external_order_id]);

            return $order;
        });
    }

    /** Replace the lines (base-unit quantities, line totals from the given unit prices) and the order total. */
    private function writeLines(SalesOrder $order, array $lines): void
    {
        SalesOrderLine::query()->where('sales_order_id', $order->id)->delete();
        $total = '0';
        foreach (array_values($lines) as $i => $line) {
            [$item, $variantId, $packQty] = $this->resolveItem($line, $i);
            [$uomId, $base, $factor] = $packQty === null ? $this->lines->toBase($item->id, $line['uom_id'] ?? null, $line['quantity'])
                : [$item->base_uom_id, D::mul($line['quantity'], $packQty, D::QTY_SCALE), $packQty];
            $lineTotal = isset($line['unit_price']) ? D::mul($line['quantity'], $line['unit_price'], D::MONEY_SCALE) : '0';
            $total = D::add($total, $lineTotal, D::MONEY_SCALE);
            SalesOrderLine::query()->create([
                'sales_order_id' => $order->id, 'line_no' => $i + 1, 'item_id' => $item->id, 'item_variant_id' => $variantId,
                'uom_id' => $uomId, 'conversion_factor_snapshot' => $factor, 'quantity' => $line['quantity'], 'base_quantity' => $base,
                'unit_price' => $line['unit_price'] ?? null, 'line_total' => $lineTotal, 'notes' => $line['notes'] ?? null,
            ]);
        }
        $order->forceFill(['total' => $total])->save();
    }

    public function assertChannelEnabled(SalesChannel $channel): void
    {
        $flag = $channel->featureFlag();
        if (! $this->config->feature($flag)) {
            throw DomainException::featureDisabled($flag);
        }
    }

    private function authorizeIntake(UserAccount $actor, SalesChannel $channel, string $warehouseId): void
    {
        if ($channel === SalesChannel::ECOMMERCE && $this->access->can($actor, 'fulfillment.sales_order.intake_api', $warehouseId)) {
            return;
        }
        $this->authorizeWarehouse($actor, 'fulfillment.sales_order.create', $warehouseId);
    }

    private function customer(?string $partnerId, ?string $branchId): string
    {
        if ($partnerId === null) {
            $partnerId = $this->config->get('fulfillment.sales.walk_in_customer_partner_id', ['branch' => $branchId])
                ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Select a customer (no walk-in customer is configured).', ['field' => 'customer_partner_id']);

            return (string) $partnerId;
        }
        $partner = Partner::query()->findOrFail($partnerId);
        if (! $partner->hasType(PartnerType::CUSTOMER)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$partner->name} is not a customer.", ['field' => 'customer_partner_id']);
        }

        return $partner->id;
    }

    /**
     * Item by id, SKU (item or variant) or barcode — integrations rarely know internal ids.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: Item, 1: ?string, 2: ?string} [item, variantId, base qty per unit when a packaging barcode was scanned]
     */
    private function resolveItem(array $line, int $i): array
    {
        if (! empty($line['item_id'])) {
            return [$this->lines->item($line['item_id']), $line['item_variant_id'] ?? null, null];
        }
        if (! empty($line['sku'])) {
            $itemId = Item::query()->where('sku', $line['sku'])->value('id');
            if ($itemId !== null) {
                return [$this->lines->item($itemId), $line['item_variant_id'] ?? null, null];
            }
            $variant = DB::table('item_variants')->where('sku', $line['sku'])->first(['id', 'item_id']);
            if ($variant !== null) {
                return [$this->lines->item($variant->item_id), $variant->id, null];
            }
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Unknown SKU {$line['sku']}.", ['field' => "lines.$i.sku"]);
        }
        if (! empty($line['barcode'])) {
            $barcode = DB::table('item_barcodes as b')->leftJoin('item_packagings as p', 'p.id', '=', 'b.item_packaging_id')
                ->where('b.barcode', $line['barcode'])->first(['b.item_id', 'b.item_variant_id', 'p.contained_qty']);
            if ($barcode !== null) {
                return [$this->lines->item($barcode->item_id), $barcode->item_variant_id, $barcode->contained_qty === null ? null : (string) $barcode->contained_qty];
            }
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Unknown barcode {$line['barcode']}.", ['field' => "lines.$i.barcode"]);
        }
        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Identify the item by id, SKU or barcode.', ['field' => "lines.$i.item_id"]);
    }
}
