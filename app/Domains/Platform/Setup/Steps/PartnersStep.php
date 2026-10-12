<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Partners\Actions\SavePartnerAction;
use App\Domains\Partners\Models\Partner;
use App\Domains\Partners\Schemas\PartnerSchema;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Seeds\LookupsSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Schema\SchemaValidator;
use App\Support\Schema\ValidationContext;
use Illuminate\Support\Facades\DB;

/**
 * Step 9 (optional) — partner foundations: system lookups (payment terms, incoterms…) and an
 * optional initial list of suppliers/customers/carriers. Payload: {partners: [PartnerSchema…]}
 */
final class PartnersStep implements SetupStepHandler
{
    public function __construct(private readonly SeedRunner $seeds, private readonly SchemaValidator $validator, private readonly SavePartnerAction $save) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::PARTNERS;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $this->seeds->run(LookupsSeed::class);
        if (($payload['skipped'] ?? false) === true) {
            return ['skipped' => true];
        }
        $created = 0;
        DB::transaction(function () use ($payload, $context, &$created) {
            foreach ((array) ($payload['partners'] ?? []) as $partner) {
                if (Partner::query()->where('code', $partner['code'] ?? '')->exists()) {
                    continue;
                }
                $this->save->execute($this->validator->validate(PartnerSchema::class, $partner, new ValidationContext('create')), $context->actor);
                $created++;
            }
        });

        return ['partners_created' => $created];
    }

    public function current(): array
    {
        return ['partners' => Partner::query()->count()];
    }
}
