<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * System lookups (Chapter 03 §3.4): admin-editable lists whose values carry no code semantics.
 * New values may be added by clients; system codes are never removed.
 */
final class LookupsSeed extends Seed
{
    /** lookup code → [label, [value code => label]] */
    public const DATA = [
        'adjustment_reasons' => ['Adjustment reasons', ['count_variance' => 'Count variance', 'damage' => 'Damage', 'loss' => 'Loss / theft', 'expiry' => 'Expiry write-off', 'found' => 'Found stock', 'data_correction' => 'Data correction', 'other' => 'Other']],
        'discrepancy_reasons' => ['Transfer discrepancy reasons', ['short_shipped' => 'Short shipped', 'damaged_in_transit' => 'Damaged in transit', 'lost_in_transit' => 'Lost in transit', 'wrong_item' => 'Wrong item', 'wrong_lot' => 'Wrong lot / serial', 'excess' => 'Excess received', 'other' => 'Other']],
        'rejection_reasons' => ['Receipt rejection reasons', ['damaged' => 'Damaged', 'wrong_item' => 'Wrong item', 'expired' => 'Expired / short dated', 'quality_failure' => 'Failed quality inspection', 'not_ordered' => 'Not ordered', 'other' => 'Other']],
        'return_reasons' => ['Return reasons', ['defective' => 'Defective', 'wrong_item' => 'Wrong item delivered', 'no_longer_needed' => 'No longer needed', 'excess' => 'Excess quantity', 'damaged' => 'Damaged', 'other' => 'Other']],
        'cancellation_reasons' => ['Cancellation reasons', ['duplicate' => 'Duplicate document', 'requirement_changed' => 'Requirement changed', 'supplier_unavailable' => 'Supplier unavailable', 'entered_in_error' => 'Entered in error', 'other' => 'Other']],
        'scrap_reasons' => ['Scrap reasons', ['damaged_beyond_repair' => 'Damaged beyond repair', 'expired' => 'Expired', 'contaminated' => 'Contaminated', 'obsolete' => 'Obsolete', 'other' => 'Other']],
        'condition_change_reasons' => ['Condition change reasons', ['qc_hold' => 'Quality hold', 'qc_release' => 'Quality release', 'damage_found' => 'Damage found', 'investigation' => 'Under investigation', 'other' => 'Other']],
        'recall_reasons' => ['Recall reasons', ['supplier_recall' => 'Supplier recall', 'regulatory' => 'Regulatory order', 'quality_defect' => 'Quality defect', 'contamination' => 'Contamination', 'other' => 'Other']],
        'payment_terms' => ['Payment terms', ['immediate' => 'Immediate', 'net_15' => 'Net 15 days', 'net_30' => 'Net 30 days', 'net_60' => 'Net 60 days', 'cod' => 'Cash on delivery', 'advance' => 'Advance payment']],
        'incoterms' => ['Incoterms', ['exw' => 'EXW — Ex Works', 'fca' => 'FCA — Free Carrier', 'cpt' => 'CPT — Carriage Paid To', 'cip' => 'CIP — Carriage and Insurance Paid', 'dap' => 'DAP — Delivered at Place', 'dpu' => 'DPU — Delivered at Place Unloaded', 'ddp' => 'DDP — Delivered Duty Paid', 'fas' => 'FAS — Free Alongside Ship', 'fob' => 'FOB — Free on Board', 'cfr' => 'CFR — Cost and Freight', 'cif' => 'CIF — Cost, Insurance and Freight']],
        'industries' => ['Industries', ['retail' => 'Retail', 'wholesale_distribution' => 'Wholesale & distribution', 'manufacturing' => 'Manufacturing', 'healthcare' => 'Healthcare & pharmacy', 'food_beverage' => 'Food & beverage', 'construction' => 'Construction', 'agriculture' => 'Agriculture', 'public_sector' => 'Public sector', 'ngo' => 'NGO / humanitarian', 'hospitality' => 'Hospitality', 'education' => 'Education', 'energy_utilities' => 'Energy & utilities', 'telecommunications' => 'Telecommunications', 'transport_logistics' => 'Transport & logistics', 'other' => 'Other']],
        'departments' => ['Departments / cost centres', ['administration' => 'Administration', 'operations' => 'Operations', 'maintenance' => 'Maintenance', 'sales' => 'Sales', 'finance' => 'Finance', 'it' => 'IT', 'hr' => 'Human resources', 'production' => 'Production', 'logistics' => 'Logistics']],
        'carrier_services' => ['Carrier services', ['standard' => 'Standard', 'express' => 'Express', 'same_day' => 'Same day', 'freight' => 'Freight', 'own_fleet' => 'Own fleet']],
        'packaging_types' => ['Packaging types', ['box' => 'Box', 'carton' => 'Carton', 'pallet' => 'Pallet', 'crate' => 'Crate', 'bag' => 'Bag', 'drum' => 'Drum', 'roll' => 'Roll', 'bundle' => 'Bundle', 'envelope' => 'Envelope']],
        'brands' => ['Brands', []],
        'purchase_warnings' => ['Purchase warnings', ['discontinued' => 'Discontinued by supplier', 'long_lead_time' => 'Long lead time', 'price_review' => 'Price under review']],
        'sales_warnings' => ['Sales warnings', ['restricted' => 'Restricted sale', 'age_check' => 'Age verification required', 'fragile' => 'Fragile — handle with care']],
    ];

    public function name(): string
    {
        return 'lookups';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $inserted = 0;
        foreach (self::DATA as $code => [$label, $values]) {
            DB::table('lookups')->insertOrIgnore(['id' => (string) Str::uuid7(), 'code' => $code, 'label' => $label, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
            $lookupId = DB::table('lookups')->where('code', $code)->value('id');
            $order = 0;
            foreach ($values as $valueCode => $valueLabel) {
                $inserted += DB::table('lookup_values')->insertOrIgnore([
                    'id' => (string) Str::uuid7(), 'lookup_id' => $lookupId, 'code' => $valueCode, 'label' => $valueLabel,
                    'sort_order' => $order += 10, 'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return ['inserted_values' => $inserted];
    }
}
