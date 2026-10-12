<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\IdentityAccess\Models\ApprovalPolicy;
use App\Domains\IdentityAccess\Models\SodRule;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Seeds\ApprovalPoliciesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/**
 * Step 11 — stock, receiving, transfer, fulfillment and stocktaking policies, plus default
 * separation-of-duties rules and approval tiers.
 * Payload: {config: {…}, sod_rules: {code: rule_type}, approval_tiers: {document_type: [tiers]}}
 */
final class StockApprovalPoliciesStep extends ConfigOnlyStep
{
    public function __construct(ConfigApplier $applier, private readonly SeedRunner $seeds)
    {
        parent::__construct($applier);
    }

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::STOCK_APPROVAL_POLICIES;
    }

    protected function prefixes(): array
    {
        return ['stock.', 'procurement.', 'receiving.', 'transfers.', 'fulfillment.', 'stocktaking.', 'approval.'];
    }

    protected function after(array $payload, SetupContext $context): void
    {
        $this->seeds->run(SodRulesSeed::class);
        $this->seeds->run(ApprovalPoliciesSeed::class);

        foreach ((array) ($payload['sod_rules'] ?? []) as $code => $ruleValue) {
            $rule = SodRuleType::tryFrom((string) $ruleValue) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                "Invalid SoD rule type for `$code`.", ['allowed_values' => SodRuleType::values()]);
            SodRule::query()->where('code', $code)->update(['rule' => $rule->value, 'updated_by' => $context->actor?->id]);
        }
        foreach ((array) ($payload['approval_tiers'] ?? []) as $typeValue => $tiers) {
            $type = DocumentType::tryFrom((string) $typeValue) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Unknown document type `$typeValue`.");
            ApprovalPolicy::query()->where('document_type', $type->value)->update(['tiers' => json_encode(self::validateTiers((array) $tiers)), 'updated_by' => $context->actor?->id]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<int, array<string, mixed>>
     */
    public static function validateTiers(array $tiers): array
    {
        if ($tiers === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Provide at least one approval tier.');
        }
        $out = [];
        $previousMax = '0';
        foreach (array_values($tiers) as $i => $tier) {
            $min = (string) ($tier['min_value'] ?? '0');
            $max = isset($tier['max_value']) && $tier['max_value'] !== null ? (string) $tier['max_value'] : null;
            if (! is_numeric($min) || ($max !== null && (! is_numeric($max) || bccomp($max, $min, 6) <= 0)) || bccomp($min, $previousMax, 6) !== 0) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Approval tier $i must start where the previous tier ends and have max > min.");
            }
            $approvals = (int) ($tier['required_approvals'] ?? 1);
            if ($approvals < 1 || $approvals > 5) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Approval tier $i must require between 1 and 5 approvals.");
            }
            $out[] = ['min_value' => $min, 'max_value' => $max, 'required_approvals' => $approvals,
                'required_permission' => (string) ($tier['required_permission'] ?? ''), 'sequential' => (bool) ($tier['sequential'] ?? false)];
            $previousMax = $max ?? $previousMax;
            if ($max === null && $i !== count($tiers) - 1) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Only the last approval tier may be open-ended.');
            }
        }

        return $out;
    }

    public function current(): array
    {
        return parent::current() + [
            'sod_rules' => SodRule::query()->orderBy('code')->get()->toArray(),
            'approval_policies' => ApprovalPolicy::query()->orderBy('document_type')->get()->toArray(),
        ];
    }
}
