<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Fulfillment\Enums\CustomerReturnStatus as S;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Models\CustomerReturn;
use App\Domains\Fulfillment\Models\CustomerReturnLine;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Models\SalesOrderLine;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns / RMA (Chapter 08 §8.9): requested → authorized → received → inspected → posted.
 * Posting moves `customer → destination` in the inspected condition (default: returns location,
 * returned_pending_inspection) valued at the original sale cost, and links back to the order.
 */
final class CustomerReturnWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly ReturnLines $returnLines,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
    ) {}

    /** @param  array<string, mixed>  $data  validated against ReturnSchema */
    public function request(string $salesOrderId, UserAccount $actor, array $data): CustomerReturn
    {
        return DB::transaction(function () use ($salesOrderId, $actor, $data) {
            $this->assertEnabled();
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($salesOrderId);
            $this->authorizeWarehouse($actor, 'fulfillment.customer_return.authorize', $order->warehouse_id);
            if (! in_array($order->status, [SalesOrderStatus::PARTIALLY_DELIVERED, SalesOrderStatus::DELIVERED], true)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Order {$order->document_number} has nothing delivered to return.");
            }
            $warehouse = Warehouse::query()->findOrFail($order->warehouse_id);
            $warehouse->assertCapability(WarehouseCapability::CUSTOMER_RETURNS);
            $autoAuthorize = ! $this->config->bool('fulfillment.customer_return.require_rma');
            $ret = CustomerReturn::query()->forceCreate([
                'document_number' => $this->nextNumber(DocumentType::CUSTOMER_RETURN, $order->warehouse_id), 'warehouse_id' => $order->warehouse_id,
                'branch_id' => $order->branch_id, 'sales_order_id' => $order->id, 'customer_partner_id' => $order->customer_partner_id,
                'status' => $autoAuthorize ? S::AUTHORIZED : S::REQUESTED, 'document_date' => now()->toDateString(),
                'reason_lookup_value_id' => $data['reason_lookup_value_id'] ?? null, 'reason' => $data['reason'] ?? null,
                'refund_reference' => $data['refund_reference'] ?? null, 'created_by' => $actor->id,
                'approved_by' => $autoAuthorize ? $actor->id : null, 'approved_at' => $autoAuthorize ? now() : null,
            ]);
            $orderLines = SalesOrderLine::query()->where('sales_order_id', $order->id)->get()->keyBy('id');
            $pending = $this->pendingReturns($order->id);
            $no = 0;
            foreach (array_values($data['lines']) as $i => $input) {
                $ol = $orderLines->get($input['source_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown order line.', ['field' => "lines.$i.source_line_id"]);
                $returnable = D::sub(D::sub($ol->delivered_base_quantity, $ol->returned_base_quantity), $pending[$ol->id] ?? '0');
                $source = (object) ['item_id' => $ol->item_id, 'item_variant_id' => $ol->item_variant_id, 'inventory_lot_id' => $this->deliveredLot($ol->id), 'item_serial_id' => null];
                foreach ($this->returnLines->resolve($input, $source, $returnable, $warehouse, "lines.$i") as $row) {
                    CustomerReturnLine::query()->forceCreate(['customer_return_id' => $ret->id, 'line_no' => ++$no, 'sales_order_line_id' => $ol->id] + $row);
                    $pending[$ol->id] = D::add($pending[$ol->id] ?? '0', $row['base_quantity']);
                }
            }
            $this->audit($actor, 'fulfillment.customer_return.requested', $ret, null, ['rma' => $ret->document_number, 'sales_order' => $order->document_number]);
            $this->publish('fulfillment.customer_return.requested', $ret, ['rma' => $ret->document_number, 'sales_order_id' => $order->id, 'status' => $ret->status->value]);

            return $ret->refresh();
        });
    }

    public function authorize(string $id, UserAccount $actor, ?int $version = null): CustomerReturn
    {
        return $this->step($id, $actor, 'fulfillment.customer_return.authorize', S::AUTHORIZED, $version, ['approved_by' => $actor->id, 'approved_at' => now()]);
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): CustomerReturn
    {
        return $this->step($id, $actor, 'fulfillment.customer_return.authorize', S::REJECTED, $version, ['cancellation_reason' => $reason], $reason);
    }

    /** Goods physically arrived (counted against the RMA); posting happens after inspection or directly. */
    public function receive(string $id, UserAccount $actor, ?int $version = null): CustomerReturn
    {
        return $this->step($id, $actor, 'fulfillment.customer_return.receive', S::RECEIVED, $version, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results  [{line_id, resulting_condition?, destination_storage_location_id?, inspection_notes?}]
     */
    public function inspect(string $id, UserAccount $actor, array $results, ?int $version = null): CustomerReturn
    {
        return DB::transaction(function () use ($id, $actor, $results, $version) {
            $ret = $this->lockDocument(CustomerReturn::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.customer_return.inspect', $ret->warehouse_id);
            $this->expectVersion($ret, $version);
            $lines = CustomerReturnLine::query()->where('customer_return_id', $ret->id)->get()->keyBy('id');
            foreach ($results as $i => $r) {
                $line = $lines->get($r['line_id'] ?? '') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown return line.', ['field' => "lines.$i.line_id"]);
                $line->forceFill(array_filter([
                    'resulting_condition' => isset($r['resulting_condition']) ? StockCondition::from($r['resulting_condition']) : null,
                    'destination_storage_location_id' => $r['destination_storage_location_id'] ?? null,
                    'inspection_notes' => $r['inspection_notes'] ?? null,
                ], fn ($v) => $v !== null))->save();
            }
            $this->transition($ret, S::INSPECTED);
            $this->audit($actor, 'fulfillment.customer_return.inspected', $ret, null, ['lines' => count($results)]);

            return $ret;
        });
    }

    /** @param  array<string, mixed>  $options  version */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'fulfillment.customer_return.post', $idempotencyKey, ['customer_return_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $this->assertEnabled();
                $ret = $this->lockDocument(CustomerReturn::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.customer_return.post', $ret->warehouse_id);
                $this->expectVersion($ret, $options['version'] ?? null);
                if ($ret->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Customer return', $ret->document_number);
                }
                $this->assertStatus($ret, [S::RECEIVED, S::INSPECTED]);
                Warehouse::query()->findOrFail($ret->warehouse_id)->assertOperational(inbound: true);
                $order = $this->lockDocument(SalesOrder::class, $ret->sales_order_id);
                $orderLines = SalesOrderLine::query()->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $returned = [];
                $movementLines = [];
                foreach (CustomerReturnLine::query()->where('customer_return_id', $ret->id)->orderBy('line_no')->get() as $line) {
                    $ol = $orderLines[$line->sales_order_line_id];
                    $returned[$ol->id] = D::add($returned[$ol->id] ?? '0', $line->base_quantity);
                    if (D::gt(D::add($ol->returned_base_quantity, $returned[$ol->id]), $ol->delivered_base_quantity)) {
                        throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$line->line_no} returns more than was delivered.");
                    }
                    $movementLines[] = $this->returnLines->movementLine($line, InventoryLocationType::CUSTOMER, $this->saleCost($ol->id), 'customer_return_line');
                }
                $movement = $this->poster->post(new MovementRequest(MovementType::CUSTOMER_RETURN, DocumentType::CUSTOMER_RETURN, $ret->id, $ret->document_number, $ret->id,
                    $movementLines, warehouseId: $ret->warehouse_id, partnerId: $ret->customer_partner_id, reasonLookupValueId: $ret->reason_lookup_value_id,
                    reasonText: $ret->reason), $ctx);
                foreach ($returned as $lineId => $qty) {
                    $orderLines[$lineId]->forceFill(['returned_base_quantity' => D::add($orderLines[$lineId]->returned_base_quantity, $qty)])->save();
                }
                $allBack = $orderLines->every(fn ($l) => D::gte($l->returned_base_quantity, $l->base_quantity));
                if ($allBack && $order->status === SalesOrderStatus::DELIVERED) {
                    $this->transition($order, SalesOrderStatus::RETURNED);
                }
                $ret->forceFill(['status' => S::POSTED, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_movement_id' => $movement->id, 'version' => $ret->version + 1])->save();
                $ctx->audit('fulfillment.customer_return.posted', 'customer_returns', $ret->id, null, ['movement_number' => $movement->movement_number]);
                $ctx->publish('fulfillment.customer_return.posted', 'customer_returns', $ret->id, ['rma' => $ret->document_number, 'sales_order_id' => $order->id,
                    'refund_reference' => $ret->refund_reference]);

                return new PostingOutcome(['customer_return_id' => $ret->id, 'document_number' => $ret->document_number, 'status' => S::POSTED->value,
                    'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, DocumentType::CUSTOMER_RETURN->value, $ret->id);
            }, failureAudit: ['fulfillment.customer_return.post_failed', 'customer_returns', $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): CustomerReturn
    {
        return $this->step($id, $actor, 'fulfillment.customer_return.authorize', S::CANCELLED, $version,
            ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason], $reason);
    }

    /** @param  array<string, mixed>  $extra */
    private function step(string $id, UserAccount $actor, string $permission, S $to, ?int $version, array $extra, ?string $reason = null): CustomerReturn
    {
        return DB::transaction(function () use ($id, $actor, $permission, $to, $version, $extra, $reason) {
            $this->assertEnabled();
            $ret = $this->lockDocument(CustomerReturn::class, $id);
            $this->authorizeWarehouse($actor, $permission, $ret->warehouse_id);
            $this->expectVersion($ret, $version);
            $this->transition($ret, $to, $extra);
            $this->audit($actor, 'fulfillment.customer_return.'.$to->value, $ret, null, null, $reason);
            $this->publish('fulfillment.customer_return.'.$to->value, $ret, ['rma' => $ret->document_number, 'sales_order_id' => $ret->sales_order_id]);

            return $ret;
        });
    }

    private function assertEnabled(): void
    {
        if (! $this->config->feature('feature.customer_returns')) {
            throw DomainException::featureDisabled('feature.customer_returns');
        }
    }

    /** The single lot delivered for an order line, if unambiguous. */
    private function deliveredLot(string $orderLineId): ?string
    {
        $lots = $this->saleLines($orderLineId)->whereNotNull('ml.inventory_lot_id')->distinct()->pluck('ml.inventory_lot_id');

        return $lots->count() === 1 ? $lots->first() : null;
    }

    /** Weighted average unit cost of what was delivered on the order line. */
    private function saleCost(string $orderLineId): ?string
    {
        $row = $this->saleLines($orderLineId)->selectRaw('SUM(ml.quantity) AS q, SUM(ml.total_cost_snapshot) AS v')->first();

        return $row === null || $row->q === null || D::isZero((string) $row->q) ? null : D::div((string) $row->v, (string) $row->q, D::COST_SCALE);
    }

    private function saleLines(string $orderLineId): \Illuminate\Database\Query\Builder
    {
        return DB::table('stock_movement_lines as ml')->join('stock_movements as m', 'm.id', '=', 'ml.stock_movement_id')
            ->where('m.movement_type', MovementType::SALE_DELIVERY->value)
            ->where(fn ($q) => $q->where(fn ($d) => $d->where('ml.source_line_type', 'sales_order_line')->where('ml.source_line_id', $orderLineId))
                ->orWhere(fn ($s) => $s->where('ml.source_line_type', 'shipment_package_line')
                    ->whereIn('ml.source_line_id', DB::table('shipment_package_lines')->where('sales_order_line_id', $orderLineId)->select('id'))));
    }

    /** @return array<string, string> order line id → qty on returns not yet posted, rejected or cancelled */
    private function pendingReturns(string $orderId): array
    {
        return DB::table('customer_return_lines as l')->join('customer_returns as r', 'r.id', '=', 'l.customer_return_id')
            ->where('r.sales_order_id', $orderId)->whereNotIn('r.status', [S::POSTED->value, S::REJECTED->value, S::CANCELLED->value])
            ->groupBy('l.sales_order_line_id')->selectRaw('l.sales_order_line_id, SUM(l.base_quantity) AS q')->pluck('q', 'sales_order_line_id')
            ->map(fn ($q) => D::exact((string) $q))->all();
    }
}
