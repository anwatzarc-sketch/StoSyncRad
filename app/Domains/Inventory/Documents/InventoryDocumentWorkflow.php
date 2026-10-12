<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Documents;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Inventory\Services\ValuationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Enums\StateEnum;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared lifecycle for single-warehouse inventory documents (opening balance, relocation,
 * condition change, scrap): draft → [pending approval → approved] → posted, cancel before posting.
 * Each concrete workflow maps its lines to ledger movements; InventoryPoster stays the only writer.
 */
abstract class InventoryDocumentWorkflow
{
    use ManagesDocuments;

    public function __construct(
        protected readonly LineQuantities $lines,
        protected readonly ApprovalService $approvals,
        protected readonly PlatformConfig $config,
        protected readonly PostingPipeline $pipeline,
        protected readonly InventoryPoster $poster,
        protected readonly InventoryLocationResolver $locations,
        protected readonly LotService $lots,
        protected readonly ValuationService $valuation,
    ) {}

    abstract protected function documentType(): DocumentType;

    /** @return class-string<Model> */
    abstract protected function headerClass(): string;

    /** @return class-string<Model> */
    abstract protected function lineClass(): string;

    /** @return class-string<StateEnum&\BackedEnum> */
    abstract protected function statusEnum(): string;

    /** Permission for an action: create, approve, post, cancel. */
    abstract protected function permission(string $action, ?Model $doc = null): string;

    /**
     * Validate one payload line and return the row(s) to store.
     *
     * @param  array<string, mixed>  $line
     * @return array<int, array<string, mixed>>
     */
    abstract protected function lineRows(Model $doc, array $line, int $i): array;

    /**
     * Movements to post for the document (built inside the posting transaction).
     *
     * @param  Collection<int, Model>  $lines
     * @return array<int, MovementRequest>
     */
    abstract protected function movementRequests(Model $doc, Collection $lines, ?UserAccount $actor): array;

    protected function requiresApproval(Model $doc): bool
    {
        return true;
    }

    protected function capability(): ?WarehouseCapability
    {
        return null;
    }

    /** @return array<int, string> extra header attributes accepted from the payload */
    protected function headerFields(): array
    {
        return [];
    }

    protected function label(): string
    {
        return str_replace('_', ' ', $this->documentType()->value);
    }

    private function event(string $suffix): string
    {
        return 'inventory.'.$this->documentType()->value.'.'.$suffix;
    }

    private function status(string $value): StateEnum
    {
        return ($this->statusEnum())::from($value);
    }

    /** @param  array<string, mixed>  $data */
    public function save(array $data, UserAccount $actor, ?string $id = null): Model
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            $class = $this->headerClass();
            if ($id === null) {
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                $this->authorizeWarehouse($actor, $this->permission('create'), $warehouse->id);
                if ($this->capability() !== null) {
                    $warehouse->assertCapability($this->capability());
                }
                $doc = new $class;
                $doc->forceFill([
                    'document_number' => $this->nextNumber($this->documentType(), $warehouse->id), 'warehouse_id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id, 'status' => $this->status('draft'), 'created_by' => $actor->id,
                    'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $doc = $this->lockDocument($class, $id);
                $this->authorizeWarehouse($actor, $this->permission('create', $doc), $doc->warehouse_id);
                $this->expectVersion($doc, $data['version'] ?? null);
                $this->assertStatus($doc, [$this->status('draft')]);
                $doc->forceFill(['version' => $doc->version + 1]);
            }
            $doc->forceFill(array_intersect_key($data, array_flip(array_merge(['document_date', 'reference', 'notes'], $this->headerFields()))));
            $doc->forceFill(['updated_by' => $actor->id])->save();
            if (array_key_exists('lines', $data)) {
                ($this->lineClass())::query()->where('document_id', $doc->id)->delete();
                $no = 0;
                foreach (array_values($data['lines']) as $i => $line) {
                    foreach ($this->lineRows($doc, $line, $i) as $row) {
                        ($this->lineClass())::query()->forceCreate(['document_id' => $doc->id, 'line_no' => ++$no, 'notes' => $line['notes'] ?? null] + $row);
                    }
                }
            }
            $this->audit($actor, $this->event($id === null ? 'created' : 'updated'), $doc, null, ['document_number' => $doc->document_number]);

            return $doc->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): Model
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $doc = $this->lockDocument($this->headerClass(), $id);
            $this->authorizeWarehouse($actor, $this->permission('create', $doc), $doc->warehouse_id);
            $this->expectVersion($doc, $version);
            if (! ($this->lineClass())::query()->where('document_id', $doc->id)->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The document has no lines.');
            }
            if (! $this->requiresApproval($doc)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, ucfirst($this->label()).' documents are posted directly; no approval is needed.');
            }
            $this->transition($doc, $this->status('pending_approval'), ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record($this->documentType(), $doc->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, $this->event('submitted'), $doc);
            $this->publish($this->event('submitted'), $doc, ['document_number' => $doc->document_number, 'warehouse_id' => $doc->warehouse_id]);

            return $doc;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): Model
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $doc = $this->lockDocument($this->headerClass(), $id);
            $this->authorizeWarehouse($actor, $this->permission('approve', $doc), $doc->warehouse_id);
            $this->expectVersion($doc, $version);
            $this->assertStatus($doc, [$this->status('pending_approval')]);
            $value = $this->value($doc);
            $this->enforceSod($this->documentType(), DocumentAction::APPROVE, $this->documentActors($doc, $this->documentType()), $actor, $doc->id, $value, null, $overrideReason);
            $complete = $this->approvals->approve($this->documentType(), $doc->id, $actor, $value, null, ['warehouse_id' => $doc->warehouse_id, 'branch_id' => $doc->branch_id], $comment);
            if ($complete) {
                $this->transition($doc, $this->status('approved'), ['approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                $doc->forceFill(['version' => $doc->version + 1])->save();
            }
            $this->audit($actor, $this->event('approval_recorded'), $doc, null, ['fully_approved' => $complete, 'value' => $value], $comment);

            return $doc;
        });
    }

    /** @param  array<string, mixed>  $options  version, override_reason */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        $type = $this->documentType();

        return $this->pipeline->run($actor, 'inventory.'.$type->value.'.post', $idempotencyKey, ['document_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options, $type) {
                $doc = $this->lockDocument($this->headerClass(), $id);
                $this->authorizeWarehouse($actor, $this->permission('post', $doc), $doc->warehouse_id);
                $this->expectVersion($doc, $options['version'] ?? null);
                if ($doc->status === $this->status('posted')) {
                    throw DomainException::alreadyPosted(ucfirst($this->label()), $doc->document_number);
                }
                $this->assertStatus($doc, $this->requiresApproval($doc) ? [$this->status('approved')] : [$this->status('draft')]);
                Warehouse::query()->findOrFail($doc->warehouse_id)->assertOperational();
                $this->enforceSod($type, DocumentAction::POST, $this->documentActors($doc, $type), $actor, $doc->id, null, null, $options['override_reason'] ?? null);
                $lines = ($this->lineClass())::query()->where('document_id', $doc->id)->orderBy('line_no')->get();
                $movements = array_map(fn (MovementRequest $r) => $this->poster->post($r, $ctx), $this->movementRequests($doc, $lines, $actor));
                $doc->forceFill(['status' => $this->status('posted'), 'posted_by' => $actor->id, 'posted_at' => now(), 'version' => $doc->version + 1])->save();
                $numbers = array_map(fn (StockMovement $m) => $m->movement_number, $movements);
                $ctx->audit($this->event('posted'), $doc->getTable(), $doc->id, null, ['document_number' => $doc->document_number, 'movements' => $numbers]);
                $ctx->publish($this->event('posted'), $doc->getTable(), $doc->id, ['document_number' => $doc->document_number, 'warehouse_id' => $doc->warehouse_id]);

                return new PostingOutcome(['document_id' => $doc->id, 'document_number' => $doc->document_number, 'status' => 'posted',
                    'movement_numbers' => $numbers, 'stock_movement_ids' => array_map(fn (StockMovement $m) => $m->id, $movements)], 201, $type->value, $doc->id);
            }, failureAudit: [$this->event('post_failed'), (new ($this->headerClass()))->getTable(), $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): Model
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $doc = $this->lockDocument($this->headerClass(), $id);
            $this->authorizeWarehouse($actor, $this->permission('cancel', $doc), $doc->warehouse_id);
            $this->expectVersion($doc, $version);
            $this->transition($doc, $this->status('cancelled'), ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, $this->event('cancelled'), $doc, null, null, $reason);

            return $doc;
        });
    }

    /** Approval value: Σ |qty| × cost (line unit cost when known, else the current estimate). */
    protected function value(Model $doc): string
    {
        $sum = '0';
        foreach (($this->lineClass())::query()->where('document_id', $doc->id)->get() as $line) {
            $storageId = $line->storage_location_id ?? $line->from_storage_location_id;
            $cost = $line->unit_cost ?? $this->valuation->estimateCost($line->item_id, $line->item_variant_id, $this->valuation->bucket($this->locations->forStorageLocation($storageId)));
            $sum = D::add($sum, D::mul($line->quantity, $cost, D::MONEY_SCALE), D::MONEY_SCALE);
        }

        return D::trim($sum);
    }

    // ---- Shared line helpers -------------------------------------------------------------

    protected function storageIn(Model $doc, string $storageLocationId, string $field): StorageLocation
    {
        $storage = StorageLocation::query()->findOrFail($storageLocationId);
        if ($storage->warehouse_id !== $doc->warehouse_id) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The location belongs to another warehouse.', ['field' => $field]);
        }

        return $storage;
    }

    /**
     * Resolve an existing serial (for moves of stock already on hand) and enforce one unit per line.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: ?string, 1: ?string} [serial id, lot id]
     */
    protected function existingSerial(array $line, int $i): array
    {
        $item = $this->lines->item($line['item_id'], false);
        if (! $item->tracking_mode->requiresSerial()) {
            return [null, $line['inventory_lot_id'] ?? null];
        }
        if (! D::eq($line['quantity'], '1') || empty($line['serial_number'])) {
            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, "{$item->label()} is serial-tracked; one line per serial.", ['field' => "lines.$i.serial_number"]);
        }
        $serial = ItemSerial::query()->where('item_id', $item->id)->where('serial_number', $line['serial_number'])->first()
            ?? throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$line['serial_number']} is not known.", ['field' => "lines.$i.serial_number"]);

        return [$serial->id, $serial->inventory_lot_id ?? ($line['inventory_lot_id'] ?? null)];
    }
}
