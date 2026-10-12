<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\IdentityAccess\Models\SodRule;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;

/**
 * Separation-of-duties enforcement (Chapter 04 §4.7).
 *
 * Given the document's recorded actors per action and the action being attempted, every active
 * rule whose first action was performed by the same account is evaluated:
 *   different_actor_required    → SOD_VIOLATION
 *   different_actor_recommended → allowed only with identity.sod.override and a reason (audited)
 * Rules with a threshold apply only when the document value/quantity meets it.
 */
final class SodGuard
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditRecorder $audit,
        private readonly PlatformConfig $config,
    ) {}

    /**
     * @param  array<string, string|array<int, string>|null>  $actorsByAction  DocumentAction value → account id(s) who performed it
     */
    public function enforce(
        DocumentType $type,
        DocumentAction $attempted,
        array $actorsByAction,
        UserAccount $actor,
        string $documentId,
        ?string $value = null,
        ?string $quantity = null,
        ?string $overrideReason = null,
    ): void {
        if ($actor->is_super_admin && $actorsByAction === []) {
            return;
        }
        $rules = SodRule::query()->where('document_type', $type->value)->where('second_action', $attempted->value)
            ->where('is_active', true)->where('rule', '<>', SodRuleType::NONE->value)->get();

        foreach ($rules as $rule) {
            $performers = (array) ($actorsByAction[$rule->first_action->value] ?? []);
            if (! in_array($actor->id, array_filter($performers), true)) {
                continue;
            }
            if (! $this->thresholdApplies($rule->threshold ?? [], $value, $quantity)) {
                continue;
            }
            $explanation = "The same person may not both {$rule->first_action->value} and {$attempted->value} this {$type->value}.";
            if ($rule->rule === SodRuleType::DIFFERENT_ACTOR_REQUIRED) {
                throw DomainException::sodViolation($rule->code, $explanation);
            }
            // Recommended: override with permission + reason.
            $needsReason = $this->config->bool('approval.sod_override_requires_reason');
            if (! $this->access->can($actor, 'identity.sod.override') || ($needsReason && ($overrideReason === null || trim($overrideReason) === ''))) {
                throw DomainException::of(ErrorCode::SOD_VIOLATION, $explanation, ['rule' => $rule->code, 'overridable' => true],
                    'A different user should perform this step, or an authorized user may override with a reason.');
            }
            $this->audit->record($actor, 'identity.sod.overridden', $type->value, $documentId, null, null, $overrideReason,
                ['rule' => $rule->code, 'attempted' => $attempted->value]);
        }
    }

    /** @param  array<string, mixed>  $threshold */
    private function thresholdApplies(array $threshold, ?string $value, ?string $quantity): bool
    {
        if (isset($threshold['min_value']) && ($value === null || D::lt($value, (string) $threshold['min_value']))) {
            return false;
        }
        if (isset($threshold['min_quantity']) && ($quantity === null || D::lt($quantity, (string) $threshold['min_quantity']))) {
            return false;
        }

        return true;
    }
}
