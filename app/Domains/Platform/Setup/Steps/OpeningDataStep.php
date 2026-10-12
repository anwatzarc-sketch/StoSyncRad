<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use Illuminate\Support\Facades\DB;

/**
 * Step 15 (optional) — opening data. Catalog, partner and opening-balance imports run through the
 * regular import pipeline (validated, previewed, drafts only — nothing is posted during setup).
 * This step records which import batches belong to the onboarding.
 * Payload: {import_batch_ids: [uuid]}
 */
final class OpeningDataStep implements SetupStepHandler
{
    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::OPENING_DATA;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $ids = array_values(array_filter((array) ($payload['import_batch_ids'] ?? []), 'is_string'));

        return ['import_batches' => $ids === [] ? [] : DB::table('import_batches')->whereIn('id', $ids)->get(['id', 'import_type', 'status'])->toArray()];
    }

    public function current(): array
    {
        return ['import_batches' => DB::table('import_batches')->orderByDesc('created_at')->limit(50)->get(['id', 'import_type', 'status', 'created_at'])->toArray()];
    }
}
