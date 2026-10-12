<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Services;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Procurement\Enums\PurchaseOrderStatus as S;
use App\Domains\Procurement\Enums\PurchaseRequestStatus;
use App\Domains\Procurement\Models\PurchaseOrder;
use App\Domains\Procurement\Models\PurchaseOrderLine;
use App\Domains\Procurement\Models\PurchaseOrderRevision;
use App\Domains\Procurement\Models\PurchaseRequest;
use App\Domains\Procurement\Models\PurchaseRequestLine;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Purchase order lifecycle (Chapter 08 §8.2). Totals are computed server-side. Changing an
 * approved/sent order snapshots a revision and (per policy) returns it to re-approval. Orders
 * with receipts can never be cancelled — only closed, which cancels the undelivered remainder.
 */
final class PurchaseOrderWorkflow
{
    use ManagesDocuments;

    public function __construct(private readonly LineQuantities $lines, private readonly ApprovalService $approvals, private readonly PlatformConfig $config) {}

    /** @param  array<string, mixed>  $data  validated against PurchaseOrderSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            $isRevision = false;
            if ($id === null) {
                $this->authorizeWarehouse($actor, 'procurement.purchase_order.create', $data['warehouse_id']);
                $po = new PurchaseOrder;
                $po->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::PURCHASE_ORDER, $data['warehouse_id']),
                    'warehouse_id' => $data['warehouse_id'],
                    'branch_id' => Warehouse::query()->whereKey($data['warehouse_id'])->value('branch_id'),
                    'status' => S::DRAFT, 'created_by' => $actor->id, 'revision' => 0,
                    'document_date' => $data['document_date'] ?? now()->toDateString(),
                    'currency' => $data['currency'] ?? $this->config->string('locale.currency.base'),
                    'exchange_rate' => $data['exchange_rate'] ?? '1',
                ]);
            } else {
                $po = $this->lockDocument(PurchaseOrder::class, $id);
                $this->authorizeWarehouse($actor, 'procurement.purchase_order.update', $po->warehouse_id);
                $this->expectVersion($po, $data['version'] ?? null);
                if (in_array($po->status, [S::APPROVED, S::SENT], true)) {
                    $this->authorizeWarehouse($actor, 'procurement.purchase_order.revise', $po->warehouse_id);
                    if (empty($data['revision_reason'])) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Changing an approved order requires a revision reason.', ['field' => 'revision_reason']);
                    }
                    $this->snapshotRevision($po, (string) $data['revision_reason'], $actor);
                    $isRevision = true;
                } else {
                    $this->assertStatus($po, [S::DRAFT]);
                }
                $po->forceFill(['version' => $po->version + 1]);
            }

            $supplierId = $data['supplier_id'] ?? $po->supplier_id;
            $supplier = Partner::query()->findOrFail($supplierId);
            if (! $supplier->hasType(PartnerType::SUPPLIER)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The selected partner is not a supplier.', ['field' => 'supplier_id']);
            }
            if ($supplier->status !== PartnerStatus::ACTIVE && $this->config->bool('procurement.po.block_suppliers_on_hold')) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Supplier {$supplier->code} is {$supplier->status->value}.");
            }
            $currency = $data['currency'] ?? $po->currency;
            if ($currency !== $this->config->string('locale.currency.base') && ! $this->config->feature('multi_currency_purchasing')) {
                throw DomainException::featureDisabled('feature.multi_currency_purchasing');
            }
            if (! empty($data['purchase_request_id'])) {
                $pr = PurchaseRequest::query()->findOrFail($data['purchase_request_id']);
                if (! in_array($pr->status, [PurchaseRequestStatus::APPROVED, PurchaseRequestStatus::PARTIALLY_ORDERED], true)) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Only approved purchase requests can be ordered.');
                }
            } elseif ($id === null && $this->config->bool('procurement.po.require_purchase_request')) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Purchase orders must reference an approved purchase request.', ['field' => 'purchase_request_id']);
            }

            $po->fill(array_intersect_key($data, array_flip(['supplier_id', 'purchase_request_id', 'document_date', 'expected_delivery_date', 'currency',
                'exchange_rate', 'incoterm_lookup_value_id', 'payment_terms_lookup_value_id', 'supplier_reference', 'delivery_notes', 'priority',
                'reference', 'notes', 'charges_total'])));
            $po->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                $this->writeLines($po, $data['lines']);
            }
            if ($isRevision) {
                $po->forceFill(['revision' => $po->revision + 1]);
                $next = $this->config->bool('procurement.po.revision_requires_reapproval') ? S::SUBMITTED : $po->status;
                if ($next !== $po->status) {
                    $po->status->assertCanTransitionTo($next, 'purchase order');
                    $po->forceFill(['status' => $next, 'approved_by' => null, 'approved_at' => null, 'submitted_by' => $actor->id, 'submitted_at' => now()]);
                    $this->approvals->record(DocumentType::PURCHASE_ORDER, $po->id, $actor, ApprovalAction::SUBMITTED, 'Revision '.$po->revision);
                }
                $po->save();
            }
            $this->audit($actor, $id === null ? 'procurement.purchase_order.created' : ($isRevision ? 'procurement.purchase_order.revised' : 'procurement.purchase_order.updated'),
                $po, null, ['document_number' => $po->document_number, 'total' => $po->total, 'revision' => $po->revision], $data['revision_reason'] ?? null);

            return $po->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $po = $this->lockDocument(PurchaseOrder::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_order.submit', $po->warehouse_id);
            $this->expectVersion($po, $version);
            if (! $po->lines()->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add at least one line before submitting.');
            }
            $this->transition($po, S::SUBMITTED, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::PURCHASE_ORDER, $po->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'procurement.purchase_order.submitted', $po);
            $this->publish('procurement.purchase_order.submitted', $po, ['document_number' => $po->document_number, 'warehouse_id' => $po->warehouse_id]);

            return $po;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $po = $this->lockDocument(PurchaseOrder::class, $id);
            $this->expectVersion($po, $version);
            $this->assertStatus($po, [S::SUBMITTED]);
            $value = $this->baseValue($po);
            $this->enforceSod(DocumentType::PURCHASE_ORDER, DocumentAction::APPROVE, $this->documentActors($po, DocumentType::PURCHASE_ORDER),
                $actor, $po->id, $value, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::PURCHASE_ORDER, $po->id, $actor, $value, null,
                ['warehouse_id' => $po->warehouse_id, 'branch_id' => $po->branch_id], $comment);
            if ($complete) {
                $this->transition($po, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
                $this->markRequestOrdered($po);
                $this->publish('procurement.purchase_order.approved', $po, ['document_number' => $po->document_number]);
            } else {
                $po->forceFill(['version' => $po->version + 1])->save();
            }
            $this->audit($actor, 'procurement.purchase_order.approval_recorded', $po, null, ['fully_approved' => $complete, 'value' => $value], $comment);

            return $po;
        });
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null, bool $returnToDraft = false): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version, $returnToDraft) {
            $po = $this->lockDocument(PurchaseOrder::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_order.reject', $po->warehouse_id);
            $this->expectVersion($po, $version);
            $this->assertStatus($po, [S::SUBMITTED]);
            if (($reason === null || trim($reason) === '') && $this->config->bool('approval.require_comment_on_reject')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A reason is required.', ['field' => 'reason']);
            }
            // Rejected orders go back to draft for correction or are cancelled by the creator.
            $this->transition($po, S::DRAFT, ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $action = $returnToDraft ? ApprovalAction::RETURNED_FOR_CORRECTION : ApprovalAction::REJECTED;
            $this->approvals->record(DocumentType::PURCHASE_ORDER, $po->id, $actor, $action, $reason);
            $this->audit($actor, 'procurement.purchase_order.'.$action->value, $po, null, null, $reason);
            $this->publish('procurement.purchase_order.'.$action->value, $po, ['document_number' => $po->document_number]);

            return $po;
        });
    }

    public function send(string $id, UserAccount $actor, ?int $version = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $po = $this->lockDocument(PurchaseOrder::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_order.send', $po->warehouse_id);
            $this->expectVersion($po, $version);
            $this->transition($po, S::SENT, ['sent_by' => $actor->id, 'sent_at' => now()]);
            $this->audit($actor, 'procurement.purchase_order.sent', $po);
            $this->publish('procurement.purchase_order.sent', $po, ['document_number' => $po->document_number, 'supplier_id' => $po->supplier_id]);

            return $po;
        });
    }

    /** Close a partially received order: the undelivered remainder is cancelled. */
    public function close(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $po = $this->lockDocument(PurchaseOrder::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_order.close', $po->warehouse_id);
            $this->expectVersion($po, $version);
            foreach (PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->lockForUpdate()->get() as $line) {
                $open = D::sub(D::sub($line->base_quantity, $line->received_base_quantity), $line->cancelled_base_quantity);
                if (D::isPositive($open)) {
                    $line->forceFill(['cancelled_base_quantity' => D::add($line->cancelled_base_quantity, $open)])->save();
                }
            }
            $this->transition($po, S::CLOSED, ['closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit($actor, 'procurement.purchase_order.closed', $po, null, null, $reason);

            return $po;
        });
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $po = $this->lockDocument(PurchaseOrder::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_order.cancel', $po->warehouse_id);
            $this->expectVersion($po, $version);
            $received = (string) PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->sum('received_base_quantity');
            $openReceipts = DB::table('goods_receipts')->where('purchase_order_id', $po->id)->whereNotIn('status', ['posted', 'cancelled'])->exists();
            if (D::isPositive($received) || $openReceipts) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Orders with receipts cannot be cancelled; close the order instead.');
            }
            $this->transition($po, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->releaseRequestLines($po);
            $this->audit($actor, 'procurement.purchase_order.cancelled', $po, null, null, $reason);
            $this->publish('procurement.purchase_order.cancelled', $po, ['document_number' => $po->document_number]);

            return $po;
        });
    }

    /** Re-derive status from received quantities (called by goods receipt posting). */
    public function refreshReceiptStatus(PurchaseOrder $po): void
    {
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->get();
        $anyReceived = $lines->contains(fn ($l) => D::isPositive($l->received_base_quantity));
        $allDone = $lines->every(fn ($l) => D::gte(D::add($l->received_base_quantity, $l->cancelled_base_quantity), $l->base_quantity));
        $target = $allDone ? S::FULLY_RECEIVED : ($anyReceived ? S::PARTIALLY_RECEIVED : $po->status);
        if ($target !== $po->status) {
            $this->transition($po, $target);
        }
    }

    public function baseValue(PurchaseOrder $po): string
    {
        return D::mul($po->total, $po->exchange_rate ?? '1', D::MONEY_SCALE);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function writeLines(PurchaseOrder $po, array $lines): void
    {
        if (DB::table('goods_receipt_lines as l')->join('purchase_order_lines as p', 'p.id', '=', 'l.purchase_order_line_id')
            ->where('p.purchase_order_id', $po->id)->exists()) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Lines of an order that already has receipts cannot be replaced.');
        }
        PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->delete();
        $subtotal = '0';
        foreach (array_values($lines) as $i => $line) {
            [$uomId, $base, $factor] = $this->lines->toBase($line['item_id'], $line['uom_id'] ?? null, $line['quantity']);
            $item = $this->lines->item($line['item_id']);
            if (! $item->is_purchasable) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} cannot be purchased.", ['field' => "lines.$i.item_id"]);
            }
            $gross = D::mul($line['quantity'], $line['unit_price'], D::MONEY_SCALE);
            $discounted = D::sub($gross, D::mul($gross, D::div($line['discount_percent'] ?? '0', '100', 8), D::MONEY_SCALE), D::MONEY_SCALE);
            $total = D::add($discounted, D::mul($discounted, D::div($line['tax_percent'] ?? '0', '100', 8), D::MONEY_SCALE), D::MONEY_SCALE);
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $po->id, 'line_no' => $i + 1, 'purchase_request_line_id' => $line['purchase_request_line_id'] ?? null,
                'item_id' => $line['item_id'], 'item_variant_id' => $line['item_variant_id'] ?? null, 'quantity' => $line['quantity'], 'uom_id' => $uomId,
                'conversion_factor_snapshot' => $factor, 'base_quantity' => $base, 'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'] ?? '0', 'tax_percent' => $line['tax_percent'] ?? '0', 'line_total' => $total,
                'expected_date' => $line['expected_date'] ?? null, 'notes' => $line['notes'] ?? null,
            ]);
            $subtotal = D::add($subtotal, $total, D::MONEY_SCALE);
        }
        $po->forceFill(['subtotal' => $subtotal, 'total' => D::add($subtotal, $po->charges_total ?? '0', D::MONEY_SCALE)])->save();
    }

    private function snapshotRevision(PurchaseOrder $po, string $reason, UserAccount $actor): void
    {
        PurchaseOrderRevision::query()->create([
            'purchase_order_id' => $po->id, 'revision' => $po->revision, 'reason' => $reason, 'created_by' => $actor->id, 'created_at' => now(),
            'snapshot' => ['header' => $po->attributesToArray(), 'lines' => $po->lines()->get()->map->attributesToArray()->all()],
        ]);
    }

    private function markRequestOrdered(PurchaseOrder $po): void
    {
        foreach ($po->lines()->whereNotNull('purchase_request_line_id')->get() as $line) {
            DB::update('UPDATE purchase_request_lines SET ordered_base_quantity = ordered_base_quantity + ?, updated_at = now() WHERE id = ?',
                [D::exact($line->base_quantity), $line->purchase_request_line_id]);
        }
        if ($po->purchase_request_id !== null) {
            $pr = PurchaseRequest::query()->lockForUpdate()->find($po->purchase_request_id);
            if ($pr !== null) {
                $lines = $pr->lines()->get();
                $all = $lines->every(fn ($l) => D::gte($l->ordered_base_quantity, $l->base_quantity));
                $target = $all ? PurchaseRequestStatus::ORDERED : PurchaseRequestStatus::PARTIALLY_ORDERED;
                if ($pr->status !== $target && $pr->status->canTransitionTo($target)) {
                    $pr->forceFill(['status' => $target, 'version' => $pr->version + 1])->save();
                }
            }
        }
    }

    private function releaseRequestLines(PurchaseOrder $po): void
    {
        if ($po->status !== S::CANCELLED) {
            return;
        }
        foreach ($po->lines()->whereNotNull('purchase_request_line_id')->get() as $line) {
            DB::update('UPDATE purchase_request_lines SET ordered_base_quantity = GREATEST(ordered_base_quantity - ?, 0) WHERE id = ?',
                [D::exact($line->base_quantity), $line->purchase_request_line_id]);
        }
    }
}
