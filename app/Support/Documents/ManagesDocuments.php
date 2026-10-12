<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\SodGuard;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Services\DocumentNumberAllocator;
use App\Support\Enums\StateEnum;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared mechanics for business-document actions (Chapter 08 common header, Chapter 04 §4.6):
 * locking, optimistic versions, server-side warehouse authorization, state transitions,
 * numbering, SoD actor maps, audit and outbox.
 */
trait ManagesDocuments
{
    /**
     * Lock a document row for the rest of the transaction.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function lockDocument(string $class, string $id): Model
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Documents must be locked inside a transaction.');
        }
        $doc = $class::query()->lockForUpdate()->find($id);
        if ($doc === null) {
            throw DomainException::notFound(class_basename($class));
        }

        return $doc;
    }

    protected function expectVersion(Model $doc, ?int $expected): void
    {
        if ($expected !== null && (int) $doc->getAttribute('version') !== $expected) {
            throw DomainException::staleVersion($expected, (int) $doc->getAttribute('version'));
        }
    }

    /**
     * Server-side warehouse authorization: the warehouse comes from the document, never from the
     * request (Chapter 04 §4.6).
     */
    protected function authorizeWarehouse(?UserAccount $actor, string $permission, ?string $warehouseId): void
    {
        if ($actor === null) {
            return; // system actor (setup, scheduler)
        }
        $scope = $warehouseId === null ? null : ['warehouse_id' => $warehouseId, 'branch_id' => Warehouse::query()->whereKey($warehouseId)->value('branch_id')];
        if (! app(Access::class)->can($actor, $permission, $scope)) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You are not authorized for this operation in this warehouse.',
                array_filter(['permission' => $permission, 'warehouse_id' => $warehouseId]));
        }
    }

    /** Transfers: allowed when the actor holds the permission for either warehouse. */
    protected function authorizeEitherWarehouse(?UserAccount $actor, string $permission, string $sourceId, string $destinationId): void
    {
        if ($actor === null) {
            return;
        }
        $access = app(Access::class);
        if (! $access->can($actor, $permission, $sourceId) && ! $access->can($actor, $permission, $destinationId)) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You are not authorized for either warehouse of this transfer.', ['permission' => $permission]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    protected function transition(Model $doc, StateEnum $to, array $extra = [], string $statusColumn = 'status'): void
    {
        /** @var StateEnum $from */
        $from = $doc->getAttribute($statusColumn);
        $from->assertCanTransitionTo($to, class_basename($doc));
        $doc->forceFill([$statusColumn => $to, 'version' => (int) $doc->getAttribute('version') + 1] + $extra)->save();
    }

    protected function assertStatus(Model $doc, array $allowed, string $statusColumn = 'status'): void
    {
        $status = $doc->getAttribute($statusColumn);
        $value = $status instanceof BackedEnum ? $status->value : $status;
        $allowedValues = array_map(static fn ($s) => $s instanceof BackedEnum ? $s->value : $s, $allowed);
        if (! in_array($value, $allowedValues, true)) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION,
                class_basename($doc)." is `$value`; this operation requires: ".implode(', ', $allowedValues).'.',
                ['status' => $value, 'allowed' => $allowedValues]);
        }
    }

    protected function nextNumber(DocumentType $type, ?string $warehouseId = null): string
    {
        $context = [];
        if ($warehouseId !== null) {
            $wh = Warehouse::query()->with('branch:id,code')->find($warehouseId);
            $context = ['warehouse_id' => $warehouseId, 'warehouse_code' => $wh?->code, 'branch_id' => $wh?->branch_id, 'branch_code' => $wh?->branch?->code];
        }

        return app(DocumentNumberAllocator::class)->next($type, $context);
    }

    /**
     * Who performed which action on a document — input for SodGuard.
     *
     * @return array<string, array<int, string>>
     */
    protected function documentActors(Model $doc, DocumentType $type): array
    {
        $map = [
            DocumentAction::CREATE->value => [$doc->getAttribute('created_by')],
            DocumentAction::SUBMIT->value => [$doc->getAttribute('submitted_by')],
            DocumentAction::APPROVE->value => [$doc->getAttribute('approved_by')],
            DocumentAction::POST->value => [$doc->getAttribute('posted_by')],
        ];
        $approvals = DB::table('approval_records')->where('document_type', $type->value)->where('document_id', $doc->getKey())
            ->where('action', 'approved')->pluck('actor_account_id')->all();
        $map[DocumentAction::APPROVE->value] = array_merge($map[DocumentAction::APPROVE->value], $approvals);

        return array_map(static fn (array $ids) => array_values(array_filter($ids)), $map);
    }

    /** @param  array<string, array<int, string>>  $actors */
    protected function enforceSod(DocumentType $type, DocumentAction $attempted, array $actors, ?UserAccount $actor, string $documentId,
        ?string $value = null, ?string $quantity = null, ?string $overrideReason = null): void
    {
        if ($actor !== null) {
            app(SodGuard::class)->enforce($type, $attempted, $actors, $actor, $documentId, $value, $quantity, $overrideReason);
        }
    }

    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @param  array<string,mixed>  $context
     */
    protected function audit(?UserAccount $actor, string $event, Model $doc, ?array $before = null, ?array $after = null, ?string $reason = null, array $context = []): void
    {
        app(AuditRecorder::class)->record($actor, $event, $doc->getTable(), (string) $doc->getKey(), $before, $after, $reason, $context);
    }

    /** @param  array<string,mixed>  $payload */
    protected function publish(string $event, Model $doc, array $payload = []): void
    {
        app(Outbox::class)->publish($event, $doc->getTable(), (string) $doc->getKey(), $payload);
    }
}
