<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Models\ApprovalPolicy;
use App\Domains\IdentityAccess\Models\ApprovalRecord;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Http\Correlation;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Collection;

/**
 * Approval tiers and limits (Chapter 04 §4.8).
 *
 * The policy for a document type selects a tier by value (or quantity); the tier names how many
 * distinct approvals are required and which permission approvers must hold. Individual approvers
 * are capped by `max_value` / `max_quantity` constraints on their role permission. Approvals are
 * recorded append-only; a re-submission starts a new approval round.
 */
final class ApprovalService
{
    public function __construct(private readonly Access $access) {}

    /** @return array{required_approvals: int, required_permission: string, sequential: bool, tier: int} */
    public function tierFor(DocumentType $type, string $value): array
    {
        $default = SaveRoleAction::approvePermissionFor($type) ?? 'identity.approval_policy.manage';
        /** @var ApprovalPolicy|null $policy */
        $policy = ApprovalPolicy::query()->where('document_type', $type->value)->where('is_active', true)->first();
        if ($policy === null || $policy->tiers === []) {
            return ['required_approvals' => 1, 'required_permission' => $default, 'sequential' => false, 'tier' => 1];
        }
        foreach (array_values($policy->tiers) as $i => $tier) {
            $min = (string) ($tier['min_value'] ?? '0');
            $max = $tier['max_value'] ?? null;
            if (D::gte($value, $min) && ($max === null || D::lt($value, (string) $max))) {
                return [
                    'required_approvals' => max(1, (int) ($tier['required_approvals'] ?? 1)),
                    'required_permission' => (string) ($tier['required_permission'] ?? $default),
                    'sequential' => (bool) ($tier['sequential'] ?? false),
                    'tier' => $i + 1,
                ];
            }
        }
        $last = end($policy->tiers);

        return ['required_approvals' => max(1, (int) ($last['required_approvals'] ?? 1)), 'required_permission' => (string) ($last['required_permission'] ?? $default),
            'sequential' => (bool) ($last['sequential'] ?? false), 'tier' => count($policy->tiers)];
    }

    /**
     * Record one approval. Returns true when the document is now fully approved.
     *
     * @param  array{warehouse_id?: ?string, branch_id?: ?string}|null  $scope
     */
    public function approve(DocumentType $type, string $documentId, UserAccount $actor, string $value, ?string $quantity, ?array $scope, ?string $comment = null): bool
    {
        $tier = $this->tierFor($type, $value);
        if (! $this->access->can($actor, $tier['required_permission'], $scope)) {
            throw DomainException::permissionDenied($tier['required_permission'], $scope['warehouse_id'] ?? null);
        }
        $limits = $this->access->constraints($actor, $tier['required_permission']);
        if (isset($limits['max_value']) && D::gt($value, (string) $limits['max_value'])) {
            throw DomainException::of(ErrorCode::APPROVAL_LIMIT_EXCEEDED, 'The document value exceeds your approval limit.',
                ['limit' => (string) $limits['max_value'], 'value' => $value]);
        }
        if ($quantity !== null && isset($limits['max_quantity']) && D::gt($quantity, (string) $limits['max_quantity'])) {
            throw DomainException::of(ErrorCode::APPROVAL_LIMIT_EXCEEDED, 'The document quantity exceeds your approval limit.',
                ['limit' => (string) $limits['max_quantity'], 'quantity' => $quantity]);
        }
        $round = $this->currentRound($type, $documentId);
        if ($round->contains(fn (ApprovalRecord $r) => $r->action === ApprovalAction::APPROVED && $r->actor_account_id === $actor->id)) {
            throw DomainException::of(ErrorCode::SOD_VIOLATION, 'You have already approved this document; another approver is required.');
        }

        $this->record($type, $documentId, $actor, ApprovalAction::APPROVED, $comment, $tier['tier'], $limits);
        $approvals = $round->where('action', ApprovalAction::APPROVED)->count() + 1;

        return $approvals >= $tier['required_approvals'];
    }

    /** @param  array<string, mixed>|null  $limitSnapshot */
    public function record(DocumentType $type, string $documentId, UserAccount $actor, ApprovalAction $action, ?string $comment = null, int $tier = 1, ?array $limitSnapshot = null): ApprovalRecord
    {
        return ApprovalRecord::query()->create([
            'document_type' => $type, 'document_id' => $documentId, 'action' => $action, 'actor_account_id' => $actor->id,
            'comment' => $comment, 'limit_snapshot' => $limitSnapshot ?: null, 'tier' => $tier, 'acted_at' => now(),
            'correlation_id' => Correlation::id(app()->runningInConsole() ? null : request()),
        ]);
    }

    /**
     * Approval records since the latest submission (a resubmission starts a new round).
     *
     * @return Collection<int, ApprovalRecord>
     */
    public function currentRound(DocumentType $type, string $documentId): Collection
    {
        $records = ApprovalRecord::query()->where('document_type', $type->value)->where('document_id', $documentId)->orderBy('acted_at')->orderBy('id')->get();
        $lastSubmit = $records->filter(fn (ApprovalRecord $r) => $r->action === ApprovalAction::SUBMITTED)->keys()->last();

        return $lastSubmit === null ? $records->values() : $records->slice($lastSubmit + 1)->values();
    }

    /** @return Collection<int, ApprovalRecord> */
    public function history(DocumentType $type, string $documentId): Collection
    {
        return ApprovalRecord::query()->where('document_type', $type->value)->where('document_id', $documentId)->orderBy('acted_at')->orderBy('id')->get();
    }
}
