<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QcChecklistsSeed extends Seed
{
    public function name(): string
    {
        return 'qc_checklists';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $templates = [
            'visual_inspection' => ['Visual inspection', [
                ['code' => 'packaging_intact', 'label' => 'Packaging intact', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
                ['code' => 'labels_match', 'label' => 'Labels match the order', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
                ['code' => 'no_visible_damage', 'label' => 'No visible damage', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
                ['code' => 'notes', 'label' => 'Notes', 'type' => 'text', 'required' => false],
            ]],
            'cold_chain_receipt' => ['Cold-chain receipt', [
                ['code' => 'temperature_c', 'label' => 'Temperature on arrival (°C)', 'type' => 'numeric', 'required' => true, 'min' => '2', 'max' => '8'],
                ['code' => 'logger_ok', 'label' => 'Data logger shows no excursion', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
                ['code' => 'packaging_intact', 'label' => 'Packaging intact', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
            ]],
            'pharma_receipt' => ['Pharmaceutical receipt', [
                ['code' => 'batch_matches_coa', 'label' => 'Batch matches certificate of analysis', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
                ['code' => 'shelf_life_ok', 'label' => 'Remaining shelf life acceptable', 'type' => 'boolean', 'required' => true, 'fail_values' => ['false']],
                ['code' => 'condition', 'label' => 'Overall condition', 'type' => 'choice', 'required' => true, 'choices' => ['good', 'acceptable', 'poor'], 'fail_values' => ['poor']],
            ]],
        ];
        $inserted = 0;
        foreach ($templates as $code => [$name, $checks]) {
            $inserted += DB::table('qc_checklist_templates')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'code' => $code, 'name' => $name, 'checks' => json_encode($checks),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['inserted' => $inserted];
    }
}
