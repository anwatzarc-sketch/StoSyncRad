<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Organization\Models\CompanyProfile;
use App\Domains\Organization\Schemas\CompanyProfileSchema;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Schema\SchemaValidator;
use App\Support\Schema\ValidationContext;
use Illuminate\Support\Facades\DB;

/** Step 3 — the single company profile (Chapter 06 §6.1). Payload: CompanyProfileSchema fields. */
final class CompanyProfileStep implements SetupStepHandler
{
    public function __construct(private readonly SchemaValidator $validator, private readonly AuditRecorder $audit, private readonly ConfigApplier $applier) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::COMPANY_PROFILE;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $data = $this->validator->validate(CompanyProfileSchema::class, $payload, new ValidationContext('create'));

        return DB::transaction(function () use ($data, $context) {
            $profile = CompanyProfile::query()->lockForUpdate()->first() ?? new CompanyProfile;
            $before = $profile->exists ? $profile->only(array_keys($data)) : null;
            $profile->fill(CompanyProfileSchema::columns($data));
            $profile->forceFill(['updated_by' => $context->actor?->id, 'created_by' => $profile->created_by ?? $context->actor?->id])->save();
            $this->applier->apply(['locale.currency.base' => $data['base_currency']], ['locale.'], $context->actor);
            $this->audit->record($context->actor, 'platform.company.updated', 'company_profile', $profile->id, $before, $profile->only(array_keys($data)));

            return ['company_profile_id' => $profile->id];
        });
    }

    public function current(): array
    {
        return ['company' => CompanyProfile::query()->first()?->toArray()];
    }
}
