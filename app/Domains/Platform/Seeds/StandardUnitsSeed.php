<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\Catalog\Enums\UomCategoryKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Standard units of measure (Chapter 06 §6.8). Codes are stable; names are editable. */
final class StandardUnitsSeed extends Seed
{
    /** category code → [kind, name, units: [code, name, symbol, ratio_to_reference, precision, whole, reference]] */
    public const DATA = [
        'unit' => [UomCategoryKind::UNIT, 'Units', [
            ['PCE', 'Piece', 'pc', '1', 0, true, true],
            ['EA', 'Each', 'ea', '1', 0, true, false],
            ['PAIR', 'Pair', 'pr', '2', 0, true, false],
            ['DZN', 'Dozen', 'dz', '12', 0, true, false],
            ['GROSS', 'Gross', 'gr', '144', 0, true, false],
            ['SET', 'Set', 'set', '1', 0, true, false],
            ['SHEET', 'Sheet', 'sh', '1', 0, true, false],
        ]],
        'packaging' => [UomCategoryKind::PACKAGING, 'Packaging', [
            ['PACK', 'Pack', 'pk', '1', 0, true, true],
            ['BOX', 'Box', 'box', '1', 0, true, false],
            ['CTN', 'Carton', 'ctn', '1', 0, true, false],
            ['BAG', 'Bag', 'bag', '1', 0, true, false],
            ['BTL', 'Bottle', 'btl', '1', 0, true, false],
            ['CAN', 'Can', 'can', '1', 0, true, false],
            ['DRUM', 'Drum', 'drm', '1', 0, true, false],
            ['ROLL', 'Roll', 'roll', '1', 0, true, false],
            ['PLT', 'Pallet', 'plt', '1', 0, true, false],
            ['TUBE', 'Tube', 'tube', '1', 0, true, false],
            ['SACHET', 'Sachet', 'sct', '1', 0, true, false],
            ['VIAL', 'Vial', 'vial', '1', 0, true, false],
            ['AMP', 'Ampoule', 'amp', '1', 0, true, false],
            ['STRIP', 'Strip', 'strip', '1', 0, true, false],
        ]],
        'weight' => [UomCategoryKind::WEIGHT, 'Weight', [
            ['KG', 'Kilogram', 'kg', '1', 6, false, true],
            ['G', 'Gram', 'g', '0.001', 6, false, false],
            ['MG', 'Milligram', 'mg', '0.000001', 6, false, false],
            ['T', 'Tonne', 't', '1000', 6, false, false],
            ['LB', 'Pound', 'lb', '0.45359237', 6, false, false],
            ['OZ', 'Ounce', 'oz', '0.028349523', 6, false, false],
            ['QTL', 'Quintal', 'q', '100', 6, false, false],
        ]],
        'volume' => [UomCategoryKind::VOLUME, 'Volume', [
            ['L', 'Litre', 'L', '1', 6, false, true],
            ['ML', 'Millilitre', 'mL', '0.001', 6, false, false],
            ['M3', 'Cubic metre', 'm³', '1000', 6, false, false],
            ['GAL', 'Gallon (US)', 'gal', '3.785411784', 6, false, false],
            ['CL', 'Centilitre', 'cL', '0.01', 6, false, false],
        ]],
        'length' => [UomCategoryKind::LENGTH, 'Length', [
            ['M', 'Metre', 'm', '1', 6, false, true],
            ['CM', 'Centimetre', 'cm', '0.01', 6, false, false],
            ['MM', 'Millimetre', 'mm', '0.001', 6, false, false],
            ['KM', 'Kilometre', 'km', '1000', 6, false, false],
            ['IN', 'Inch', 'in', '0.0254', 6, false, false],
            ['FT', 'Foot', 'ft', '0.3048', 6, false, false],
            ['YD', 'Yard', 'yd', '0.9144', 6, false, false],
        ]],
        'area' => [UomCategoryKind::AREA, 'Area', [
            ['M2', 'Square metre', 'm²', '1', 6, false, true],
            ['CM2', 'Square centimetre', 'cm²', '0.0001', 6, false, false],
            ['FT2', 'Square foot', 'ft²', '0.09290304', 6, false, false],
            ['HA', 'Hectare', 'ha', '10000', 6, false, false],
        ]],
        'time' => [UomCategoryKind::TIME, 'Time', [
            ['HR', 'Hour', 'h', '1', 6, false, true],
            ['MIN', 'Minute', 'min', '0.0166666667', 6, false, false],
            ['DAY', 'Day', 'd', '24', 6, false, false],
        ]],
    ];

    public function name(): string
    {
        return 'standard_units';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $inserted = 0;
        foreach (self::DATA as $catCode => [$kind, $catName, $units]) {
            DB::table('uom_categories')->insertOrIgnore(['id' => (string) Str::uuid7(), 'code' => $catCode, 'name' => $catName, 'kind' => $kind->value, 'created_at' => now(), 'updated_at' => now()]);
            $catId = DB::table('uom_categories')->where('code', $catCode)->value('id');
            foreach ($units as [$code, $name, $symbol, $ratio, $precision, $whole, $reference]) {
                if (DB::table('units_of_measure')->where('code', $code)->exists()) {
                    continue;
                }
                $isReference = $reference && ! DB::table('units_of_measure')->where('uom_category_id', $catId)->where('is_reference', true)->exists();
                DB::table('units_of_measure')->insert([
                    'id' => (string) Str::uuid7(), 'uom_category_id' => $catId, 'code' => $code, 'name' => $name, 'symbol' => $symbol,
                    'ratio_to_reference' => $ratio, 'rounding_precision' => $precision, 'is_reference' => $isReference,
                    'is_whole_number' => $whole, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $inserted++;
            }
        }

        return ['inserted' => $inserted];
    }
}
