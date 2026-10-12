<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Services;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Procurement\Enums\AsnStatus;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Models\AdvanceShipmentNotice;
use App\Domains\Procurement\Models\PurchaseOrder;
use App\Domains\Receiving\Models\GoodsReceipt;
use App\Domains\Receiving\Services\GoodsReceiptWorkflow;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Supplier advance shipment notices (Chapter 11 §11.8, feature.asn): announced quantities, lots,
 * expiry and serials per PO line; receiving pre-fills a draft goods receipt from the ASN.
 */
final class AsnService
{
    use ManagesDocuments;

    public function __construct(private readonly PlatformConfig $config, private readonly GoodsReceiptWorkflow $receipts) {}

    /** @param  array{supplier_reference?: ?string, expected_arrival?: ?string, lines: array<int, array<string, mixed>>}  $data */
    public function create(string $purchaseOrderId, UserAccount $actor, array $data): AdvanceShipmentNotice
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($purchaseOrderId, $actor, $data) {
            $po = PurchaseOrder::query()->findOrFail($purchaseOrderId);
            $this->authorizeWarehouse($actor, 'procurement.asn.create', $po->warehouse_id);
            if (! in_array($po->status, [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::SENT, PurchaseOrderStatus::PARTIALLY_RECEIVED], true)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Purchase order {$po->document_number} is not open for delivery.");
            }
            $poLines = DB::table('purchase_order_lines')->where('purchase_order_id', $po->id)->get()->keyBy('id');
            $lines = [];
            foreach (array_values((array) ($data['lines'] ?? [])) as $i => $l) {
                $pl = $poLines->get($l['purchase_order_line_id'] ?? '') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown order line.', ['field' => "lines.$i.purchase_order_line_id"]);
                $qty = D::exact((string) ($l['quantity'] ?? '0'));
                if (! D::isPositive($qty)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Quantities must be positive.', ['field' => "lines.$i.quantity"]);
                }
                $lines[] = ['purchase_order_line_id' => $pl->id, 'item_id' => $pl->item_id, 'item_variant_id' => $pl->item_variant_id, 'uom_id' => $pl->uom_id,
                    'quantity' => $qty, 'lot_number' => $l['lot_number'] ?? null, 'manufacture_date' => $l['manufacture_date'] ?? null,
                    'expiry_date' => $l['expiry_date'] ?? null, 'serial_numbers' => array_values((array) ($l['serial_numbers'] ?? []))];
            }
            if ($lines === []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Announce at least one line.');
            }
            $asn = AdvanceShipmentNotice::query()->forceCreate(['document_number' => $this->nextNumber(DocumentType::ADVANCE_SHIPMENT_NOTICE, $po->warehouse_id),
                'purchase_order_id' => $po->id, 'supplier_reference' => $data['supplier_reference'] ?? null, 'expected_arrival' => $data['expected_arrival'] ?? null,
                'status' => AsnStatus::OPEN, 'lines' => $lines, 'created_by' => $actor->id]);
            $this->audit($actor, 'procurement.asn.created', $asn, null, ['purchase_order' => $po->document_number, 'lines' => count($lines)]);

            return $asn;
        });
    }

    /** Draft goods receipt pre-filled from the ASN (quantities/lots editable before posting). */
    public function prefillReceipt(string $asnId, UserAccount $actor): GoodsReceipt
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($asnId, $actor) {
            $asn = AdvanceShipmentNotice::query()->lockForUpdate()->findOrFail($asnId);
            if ($asn->status !== AsnStatus::OPEN) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'This ASN has already been received or cancelled.');
            }
            $po = PurchaseOrder::query()->findOrFail($asn->purchase_order_id);
            $gr = $this->receipts->save(['purchase_order_id' => $po->id, 'warehouse_id' => $po->warehouse_id, 'supplier_id' => $po->supplier_id,
                'advance_shipment_notice_id' => $asn->id, 'supplier_delivery_reference' => $asn->supplier_reference,
                'lines' => array_map(fn ($l) => array_filter(['purchase_order_line_id' => $l['purchase_order_line_id'], 'item_id' => $l['item_id'],
                    'item_variant_id' => $l['item_variant_id'], 'uom_id' => $l['uom_id'], 'received_quantity' => $l['quantity'], 'lot_number' => $l['lot_number'],
                    'manufacture_date' => $l['manufacture_date'], 'expiry_date' => $l['expiry_date'],
                    'serial_numbers' => $l['serial_numbers'] ?: null], fn ($v) => $v !== null), (array) $asn->lines)], $actor);
            $asn->forceFill(['status' => AsnStatus::RECEIVED])->save();

            return $gr;
        });
    }

    private function assertEnabled(): void
    {
        if (! $this->config->feature('feature.asn')) {
            throw DomainException::featureDisabled('feature.asn');
        }
    }
}
