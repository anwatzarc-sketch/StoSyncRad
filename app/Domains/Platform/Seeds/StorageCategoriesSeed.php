<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StorageCategoriesSeed extends Seed
{
    public const DATA = [
        ['general', 'General storage', null],
        ['cold_chain', 'Cold chain (2–8 °C)', ['temperature_min' => 2, 'temperature_max' => 8]],
        ['frozen', 'Frozen (≤ −18 °C)', ['temperature_max' => -18]],
        ['ambient_controlled', 'Controlled ambient (15–25 °C)', ['temperature_min' => 15, 'temperature_max' => 25]],
        ['hazardous', 'Hazardous materials', ['hazard_segregation' => true]],
        ['flammable', 'Flammable materials', ['hazard_class' => '3', 'hazard_segregation' => true]],
        ['bulky', 'Bulky / oversized', null],
        ['high_value', 'High value / secured', ['secured' => true]],
        ['bonded', 'Bonded / customs', null],
    ];

    public function name(): string
    {
        return 'storage_categories';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $rows = array_map(static fn (array $r) => [
            'id' => (string) Str::uuid7(), 'code' => $r[0], 'name' => $r[1], 'constraints' => $r[2] === null ? null : json_encode($r[2]),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], self::DATA);

        return ['inserted' => DB::table('storage_categories')->insertOrIgnore($rows)];
    }
}
