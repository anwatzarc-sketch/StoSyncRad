<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\Platform\Enums\SeedRunStatus;
use App\Domains\Platform\Models\SeedRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes seeds transactionally and records each run in seed_runs (Chapter 02 §2.6).
 */
final class SeedRunner
{
    /**
     * @param  class-string<Seed>|Seed  $seed
     * @param  array<string, mixed>  $options
     * @return array<string, int>
     */
    public function run(string|Seed $seed, array $options = []): array
    {
        $seed = is_string($seed) ? app($seed) : $seed;
        $record = SeedRun::query()->create([
            'seed' => $seed->name(), 'version' => $seed->version(), 'checksum' => $seed->checksum(),
            'status' => SeedRunStatus::RUNNING, 'started_at' => now(),
        ]);
        try {
            $result = DB::transaction(fn () => $seed->run($options));
            $record->forceFill(['status' => SeedRunStatus::COMPLETED, 'finished_at' => now(), 'result' => $result])->save();

            return $result;
        } catch (Throwable $e) {
            $record->forceFill(['status' => SeedRunStatus::FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        }
    }

    /**
     * @param  array<int, class-string<Seed>>  $seeds
     * @return array<string, array<string, int>>
     */
    public function runAll(array $seeds, array $options = []): array
    {
        $out = [];
        foreach ($seeds as $class) {
            $seed = app($class);
            $out[$seed->name()] = $this->run($seed, $options[$seed->name()] ?? []);
        }

        return $out;
    }

    /** Seeds that every deployment receives (setup and platform:upgrade). */
    public static function core(): array
    {
        return [
            StandardUnitsSeed::class,
            StorageCategoriesSeed::class,
            LookupsSeed::class,
            DocumentSequencesSeed::class,
            SodRulesSeed::class,
            ApprovalPoliciesSeed::class,
            VirtualLocationsSeed::class,
            QcChecklistsSeed::class,
            TemplatesSeed::class,
        ];
    }
}
