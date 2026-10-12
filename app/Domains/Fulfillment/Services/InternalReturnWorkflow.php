<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Fulfillment\Enums\InternalReturnStatus as S;
use App\Domains\Fulfillment\Enums\StockIssueStatus;
use App\Domains\Fulfillment\Models\InternalReturn;
use App\Domains\Fulfillment\Models\InternalReturnLine;
use App\Domains\Fulfillment\Models\StockIssue;
use App\Domains\Fulfillment\Models\StockIssueLine;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Internal returns against posted stock issues (Chapter 08 §8.6): draft → inspection → approval →
 * posting `customer(internal recipient) → returns location` at the original issue cost.
 */
final class InternalReturnWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly ReturnLines $returnLines,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
    ) {}

    /** @param  array<string, mixed>  $data  validated against ReturnSchema */
    public function create(string $stockIssueId, UserAccount $actor, array $data): InternalReturn
    {
        return DB::transaction(function () use ($stockIssueId, $actor, $data) {
            $issue = StockIssue::query()->lockForUpdate()->findOrFail($stockIssueId);
            $this->authorizeWarehouse($actor, 'fulfillment.internal_return.create', $issue->warehouse_id);
            if ($issue->status !== StockIssueStatus::POSTED) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Issue {$issue->document_number} has not been posted.");
            }
            $warehouse = Warehouse::query()->findOrFail($issue->warehouse_id);
            $ret = InternalReturn::query()->forceCreate([
                'document_number' => $this->nextNumber(DocumentType::INTERNAL_RETURN, $issue->warehouse_id), 'warehouse_id' => $issue->warehouse_id,
                'branch_id' => $issue->branch_id, 'stock_issue_id' => $issue->id, 'status' => S::DRAFT, 'document_date' => now()->toDateString(),
                'reason_lookup_value_id' => $data['reason_lookup_value_id'] ?? null, 'reason' => $data['reason'] ?? null, 'created_by' => $actor->id,
            ]);
            $issueLines = StockIssueLine::query()->where('stock_issue_id', $issue->id)->get()->keyBy('id');
            $pending = $this->pendingReturns($issue->id);
            $no = 0;
            foreach (array_values($data['lines']) as $i => $input) {
                $source = $issueLines->get($input['source_line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown issue line.', ['field' => "lines.$i.source_line_id"]);
                $returnable = D::sub(D::sub($source->base_quantity, $source->returned_base_quantity), $pending[$source->id] ?? '0');
                foreach ($this->returnLines->resolve($input, $source, $returnable, $warehouse, "lines.$i") as $row) {
                    InternalReturnLine::query()->forceCreate(['internal_return_id' => $ret->id, 'line_no' => ++$no, 'stock_issue_line_id' => $source->id] + array_diff_key($row, ['item_variant_id' => 1]));
                    $pending[$source->id] = D::add($pending[$source->id] ?? '0', $row['base_quantity']);
                }
            }
            $this->audit($actor, 'fulfillment.internal_return.created', $ret, null, ['document_number' => $ret->document_number, 'stock_issue' => $issue->document_number]);

            return $ret->refresh();
        });
    }

    /**
     * Record inspection results: [{line_id, resulting_condition?, destination_storage_location_id?, inspection_notes?}].
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    public function inspect(string $id, UserAccount $actor, array $results): InternalReturn
    {
        return DB::transaction(function () use ($id, $actor, $results) {
            $ret = $this->lockDocument(InternalReturn::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.internal_return.inspect', $ret->warehouse_id);
            $this->assertStatus($ret, [S::DRAFT, S::IN_INSPECTION]);
            $lines = InternalReturnLine::query()->where('internal_return_id', $ret->id)->get()->keyBy('id');
            foreach ($results as $i => $r) {
                $line = $lines->get($r['line_id'] ?? '') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown return line.', ['field' => "lines.$i.line_id"]);
                $line->forceFill(array_filter([
                    'resulting_condition' => isset($r['resulting_condition']) ? StockCondition::from($r['resulting_condition']) : null,
                    'destination_storage_location_id' => $r['destination_storage_location_id'] ?? null,
                    'inspection_notes' => $r['inspection_notes'] ?? null,
                ], fn ($v) => $v !== null))->save();
            }
            if ($ret->status === S::DRAFT) {
                $this->transition($ret, S::IN_INSPECTION);
            }
            $this->audit($actor, 'fulfillment.internal_return.inspected', $ret, null, ['lines' => count($results)]);

            return $ret;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $overrideReason = null): InternalReturn
    {
        return DB::transaction(function () use ($id, $actor, $version, $overrideReason) {
            $ret = $this->lockDocument(InternalReturn::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.internal_return.approve', $ret->warehouse_id);
            $this->expectVersion($ret, $version);
            $this->enforceSod(DocumentType::INTERNAL_RETURN, DocumentAction::APPROVE, $this->documentActors($ret, DocumentType::INTERNAL_RETURN), $actor, $ret->id, null, null, $overrideReason);
            $this->transition($ret, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit($actor, 'fulfillment.internal_return.approved', $ret);

            return $ret;
        });
    }

    /** @param  array<string, mixed>  $options  version */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'fulfillment.internal_return.post', $idempotencyKey, ['internal_return_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $ret = $this->lockDocument(InternalReturn::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.internal_return.post', $ret->warehouse_id);
                $this->expectVersion($ret, $options['version'] ?? null);
                if ($ret->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Internal return', $ret->document_number);
                }
                $this->assertStatus($ret, [S::APPROVED]);
                Warehouse::query()->findOrFail($ret->warehouse_id)->assertOperational(inbound: true);
                $issue = StockIssue::query()->findOrFail($ret->stock_issue_id);
                $lines = InternalReturnLine::query()->where('internal_return_id', $ret->id)->orderBy('line_no')->get();
                $issueLines = StockIssueLine::query()->where('stock_issue_id', $issue->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $from = $issue->is_production_issue ? InventoryLocationType::PRODUCTION : InventoryLocationType::CUSTOMER;
                $returned = [];
                $movementLines = [];
                foreach ($lines as $line) {
                    $source = $issueLines[$line->stock_issue_line_id];
                    $returned[$source->id] = D::add($returned[$source->id] ?? '0', $line->base_quantity);
                    if (D::gt(D::add($source->returned_base_quantity, $returned[$source->id]), $source->base_quantity)) {
                        throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$line->line_no} returns more than was issued.");
                    }
                    $line->item_variant_id = $source->item_variant_id;
                    $movementLines[] = $this->returnLines->movementLine($line, $from, $source->unit_cost_snapshot, 'internal_return_line');
                }
                $movement = $this->poster->post(new MovementRequest(MovementType::INTERNAL_RETURN, DocumentType::INTERNAL_RETURN, $ret->id, $ret->document_number, $ret->id,
                    $movementLines, warehouseId: $ret->warehouse_id, partnerId: $issue->recipient_partner_id, reasonLookupValueId: $ret->reason_lookup_value_id,
                    reasonText: $ret->reason), $ctx);
                foreach ($returned as $issueLineId => $qty) {
                    $source = $issueLines[$issueLineId];
                    $source->forceFill(['returned_base_quantity' => D::add($source->returned_base_quantity, $qty)])->save();
                    if ($source->stock_request_line_id !== null) {
                        DB::update('UPDATE stock_request_lines SET returned_base_quantity = returned_base_quantity + ?, updated_at = ? WHERE id = ?', [$qty, now(), $source->stock_request_line_id]);
                    }
                }
                $ret->forceFill(['status' => S::POSTED, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_movement_id' => $movement->id, 'version' => $ret->version + 1])->save();
                $ctx->audit('fulfillment.internal_return.posted', 'internal_returns', $ret->id, null, ['movement_number' => $movement->movement_number]);
                $ctx->publish('fulfillment.internal_return.posted', 'internal_returns', $ret->id, ['document_number' => $ret->document_number, 'warehouse_id' => $ret->warehouse_id]);

                return new PostingOutcome(['internal_return_id' => $ret->id, 'document_number' => $ret->document_number, 'status' => S::POSTED->value,
                    'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, DocumentType::INTERNAL_RETURN->value, $ret->id);
            }, failureAudit: ['fulfillment.internal_return.post_failed', 'internal_returns', $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason): InternalReturn
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $ret = $this->lockDocument(InternalReturn::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.internal_return.create', $ret->warehouse_id);
            $this->transition($ret, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'fulfillment.internal_return.cancelled', $ret, null, null, $reason);

            return $ret;
        });
    }

    /** @return array<string, string> issue line id → qty on returns not yet posted or cancelled */
    private function pendingReturns(string $issueId): array
    {
        return DB::table('internal_return_lines as l')->join('internal_returns as r', 'r.id', '=', 'l.internal_return_id')
            ->where('r.stock_issue_id', $issueId)->whereNotIn('r.status', [S::POSTED->value, S::CANCELLED->value])
            ->groupBy('l.stock_issue_line_id')->selectRaw('l.stock_issue_line_id, SUM(l.base_quantity) AS q')->pluck('q', 'stock_issue_line_id')
            ->map(fn ($q) => D::exact((string) $q))->all();
    }
}
