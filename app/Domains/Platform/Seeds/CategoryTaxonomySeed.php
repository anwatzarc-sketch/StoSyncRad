<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\ItemCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeded, complete category / sub-category taxonomy (Appendix E). Every group ends with an
 * `Other` leaf so any item has a precise placement. Options:
 *   groups: array of top-level codes to import (default: all)
 */
final class CategoryTaxonomySeed extends Seed
{
    private const PERISHABLE = ['default_expiry_policy' => 'tracked_required', 'default_removal_strategy' => 'fefo', 'default_qc_policy' => 'inspect_on_receipt', 'default_tracking_mode' => 'lot'];

    /** code → [name, policy defaults, [sub code => sub name | [sub name, policy overrides]]] */
    public static function data(): array
    {
        return [
            'RAW' => ['Raw Materials', [], ['METALS' => 'Metals & Alloys', 'PLASTICS' => 'Plastics & Polymers', 'RUBBER' => 'Rubber', 'GLASS' => 'Glass', 'WOOD' => 'Wood & Timber',
                'PAPER' => 'Paper & Pulp', 'FIBRES' => 'Textile Fibres', 'MINERALS' => 'Minerals & Ores', 'AGRI_INPUTS' => 'Agricultural Inputs', 'FEEDSTOCK' => 'Chemical Feedstock']],
            'CHEM' => ['Chemicals', self::PERISHABLE + ['is_hazardous' => true], ['INDUSTRIAL' => 'Industrial Chemicals', 'REAGENTS' => 'Laboratory Reagents', 'SOLVENTS' => 'Solvents',
                'ACIDS_BASES' => 'Acids & Bases', 'ADHESIVES' => 'Adhesives & Sealants', 'PAINTS' => 'Paints & Coatings', 'DETERGENTS' => 'Detergents (industrial)',
                'WATER_TREATMENT' => 'Water Treatment', 'AGROCHEM' => 'Agro-chemicals', 'GASES' => 'Gases (industrial)']],
            'FUEL' => ['Fuels & Lubricants', ['is_hazardous' => true], ['DIESEL' => 'Diesel', 'PETROL' => 'Petrol / Gasoline', 'KEROSENE' => 'Kerosene', 'LPG' => 'LPG',
                'ENGINE_OIL' => 'Engine Oils', 'HYDRAULIC' => 'Hydraulic Fluids', 'GREASE' => 'Greases', 'COOLANT' => 'Coolants']],
            'PACK' => ['Packaging Materials', [], ['CARTONS' => 'Cartons & Boxes', 'BAGS' => 'Bags & Sacks', 'FILMS' => 'Films & Wraps', 'PALLETS' => 'Pallets', 'LABELS' => 'Labels & Tags',
                'TAPES' => 'Tapes', 'STRAPPING' => 'Strapping', 'CONTAINERS' => 'Bottles & Containers', 'FILL' => 'Protective Fill']],
            'CONS' => ['Consumables & Supplies', [], ['WORKSHOP' => 'Workshop Consumables', 'WELDING' => 'Welding Consumables', 'ABRASIVES' => 'Abrasives', 'FASTENERS' => 'Fasteners & Fixings',
                'FILTERS' => 'Filters', 'BATTERIES' => 'Batteries', 'CLEANING' => 'Cleaning Consumables', 'PRINTING' => 'Printing Consumables']],
            'SPARE' => ['Spare Parts', [], ['MECHANICAL' => 'Mechanical Parts', 'ELECTRICAL' => 'Electrical Parts', 'ELECTRONIC' => 'Electronic Modules', 'HYDRAULIC' => 'Hydraulic & Pneumatic',
                'BEARINGS' => 'Bearings & Seals', 'BELTS' => 'Belts & Chains', 'GASKETS' => 'Gaskets & O-rings', 'PUMPS' => 'Pumps & Valves', 'VEHICLE' => 'Vehicle Parts', 'HVAC' => 'HVAC Parts']],
            'ELEC' => ['Electrical Materials', [], ['CABLES' => 'Cables & Wires', 'SWITCHES' => 'Switches & Sockets', 'PROTECTION' => 'Circuit Breakers & Protection', 'LIGHTING' => 'Lighting & Lamps',
                'TRANSFORMERS' => 'Transformers', 'PANELS' => 'Panels & Enclosures', 'CONDUITS' => 'Conduits & Trunking', 'CONNECTORS' => 'Connectors & Terminals',
                'GENERATORS' => 'Generators & UPS', 'SOLAR' => 'Solar Components']],
            'MECH' => ['Mechanical Materials', [], ['PIPES' => 'Pipes & Fittings', 'STRUCTURAL' => 'Structural Steel', 'SHEET' => 'Sheet & Plate', 'BARS' => 'Bars & Profiles',
                'CASTINGS' => 'Castings & Forgings', 'SPRINGS' => 'Springs', 'COUPLINGS' => 'Couplings & Gears']],
            'TOOL' => ['Tools & Equipment', [], ['HAND' => 'Hand Tools', 'POWER' => ['Power Tools', ['default_tracking_mode' => 'serial']], 'MEASURING' => 'Measuring Instruments',
                'LIFTING' => 'Lifting Equipment', 'MACHINERY' => ['Workshop Machinery', ['default_tracking_mode' => 'serial']], 'LAB' => 'Laboratory Equipment',
                'GARDEN' => 'Garden & Grounds', 'CALIBRATION' => 'Calibration Standards']],
            'ITE' => ['IT & Electronics', [], ['COMPUTERS' => ['Computers & Laptops', ['default_tracking_mode' => 'serial']], 'SERVERS' => ['Servers & Storage', ['default_tracking_mode' => 'serial']],
                'NETWORKING' => ['Networking', ['default_tracking_mode' => 'serial']], 'PERIPHERALS' => 'Peripherals', 'MOBILE' => ['Mobile Devices', ['default_tracking_mode' => 'serial']],
                'PRINTERS' => ['Printers & Scanners', ['default_tracking_mode' => 'serial']], 'BARCODE_RFID' => 'Barcode & RFID Hardware', 'LICENCES' => 'Software Licences (non-stock)',
                'AV' => 'Audio-Visual', 'CABLES' => 'Cables & Adapters']],
            'OFF' => ['Office Supplies', [], ['PAPER' => 'Paper & Stationery', 'WRITING' => 'Writing Instruments', 'FILING' => 'Filing & Storage', 'DESK' => 'Desk Accessories',
                'PRINTER_SUPPLIES' => 'Printer Supplies', 'PRESENTATION' => 'Presentation Materials', 'MAILING' => 'Mailing & Shipping']],
            'FURN' => ['Furniture & Fixtures', [], ['OFFICE' => 'Office Furniture', 'SHELVING' => 'Shelving & Racking', 'RETAIL' => 'Retail Fixtures', 'SEATING' => 'Seating',
                'CABINETS' => 'Storage Cabinets', 'WAREHOUSE' => 'Warehouse Fixtures']],
            'SAFE' => ['Safety & PPE', [], ['HEAD' => 'Head Protection', 'EYE_FACE' => 'Eye & Face', 'HEARING' => 'Hearing', 'RESPIRATORY' => 'Respiratory', 'HAND' => 'Hand Protection',
                'FOOT' => 'Foot Protection', 'BODY_FALL' => 'Body & Fall Protection', 'FIRST_AID' => ['First Aid', self::PERISHABLE], 'FIRE' => 'Fire Safety', 'SIGNAGE' => 'Signage']],
            'CLEAN' => ['Cleaning & Hygiene', [], ['DETERGENTS' => 'Detergents & Disinfectants', 'TOOLS' => 'Cleaning Tools', 'PAPER' => 'Paper Hygiene', 'WASTE' => 'Waste Management',
                'PEST' => ['Pest Control', ['is_hazardous' => true]], 'JANITORIAL' => 'Janitorial Equipment']],
            'MED' => ['Medical & Pharmaceutical', self::PERISHABLE, ['RX' => 'Medicines (Rx)', 'OTC' => 'OTC Medicines',
                'VACC' => ['Vaccines & Biologicals (cold chain)', ['default_qc_policy' => 'quarantine_on_receipt', 'storage_category' => 'cold_chain']],
                'CONSUMABLES' => 'Medical Consumables', 'DIAGNOSTICS' => 'Diagnostics & Reagents', 'DEVICES' => ['Medical Devices', ['default_tracking_mode' => 'serial', 'default_expiry_policy' => 'tracked_optional']],
                'SURGICAL' => 'Surgical Instruments', 'NUTRITION' => 'Nutrition & Supplements', 'VETERINARY' => 'Veterinary']],
            'FOOD' => ['Food & Beverage', self::PERISHABLE, ['DRY_GOODS' => 'Dry Goods & Grains', 'PRODUCE' => 'Fresh Produce', 'DAIRY' => ['Dairy', ['storage_category' => 'cold_chain']],
                'MEAT' => ['Meat & Poultry', ['storage_category' => 'cold_chain']], 'FISH' => ['Fish & Seafood', ['storage_category' => 'cold_chain']], 'FROZEN' => ['Frozen', ['storage_category' => 'frozen']],
                'BAKERY' => 'Bakery', 'BEVERAGES' => 'Beverages (non-alcoholic)', 'ALCOHOL' => 'Beverages (alcoholic)', 'CONFECTIONERY' => 'Confectionery & Snacks',
                'SPICES' => 'Spices & Condiments', 'OILS' => 'Cooking Oils', 'CATERING' => 'Catering Supplies']],
            'TEXT' => ['Textiles & Apparel', [], ['FABRICS' => 'Fabrics', 'GARMENTS' => 'Garments', 'UNIFORMS' => 'Uniforms & Workwear', 'FOOTWEAR' => 'Footwear',
                'ACCESSORIES' => 'Accessories', 'HOME_TEXTILES' => 'Home Textiles']],
            'HOME' => ['Household & Consumer Goods', [], ['KITCHENWARE' => 'Kitchenware', 'SMALL_APPLIANCES' => 'Appliances (small)', 'LARGE_APPLIANCES' => ['Appliances (large)', ['default_tracking_mode' => 'serial']],
                'PERSONAL_CARE' => ['Personal Care', ['default_expiry_policy' => 'tracked_optional']], 'BABY_CARE' => ['Baby Care', self::PERISHABLE], 'DECOR' => 'Home Décor',
                'TOYS' => 'Toys & Games', 'SPORTS' => 'Sports & Leisure']],
            'CONST' => ['Construction Materials', [], ['CEMENT' => ['Cement & Aggregates', ['default_expiry_policy' => 'tracked_optional']], 'BRICKS' => 'Bricks & Blocks', 'ROOFING' => 'Roofing',
                'TIMBER' => 'Timber Products', 'DOORS_WINDOWS' => 'Doors & Windows', 'PLUMBING' => 'Plumbing Fixtures', 'TILES' => 'Tiles & Flooring', 'INSULATION' => 'Insulation',
                'PAINTS' => 'Paints (construction)', 'HARDWARE' => 'Hardware & Ironmongery']],
            'AGRI' => ['Agricultural & Livestock', self::PERISHABLE, ['SEEDS' => 'Seeds', 'FERTILIZERS' => 'Fertilizers', 'PESTICIDES' => ['Pesticides', ['is_hazardous' => true]],
                'FEED' => 'Animal Feed', 'VET_SUPPLIES' => 'Veterinary Supplies', 'IRRIGATION' => ['Irrigation Equipment', ['default_expiry_policy' => 'not_tracked', 'default_removal_strategy' => 'fifo', 'default_qc_policy' => 'none', 'default_tracking_mode' => 'none']],
                'FARM_TOOLS' => ['Farm Tools', ['default_expiry_policy' => 'not_tracked', 'default_removal_strategy' => 'fifo', 'default_qc_policy' => 'none', 'default_tracking_mode' => 'none']]]],
            'AUTO' => ['Vehicles & Transport', [], ['VEHICLES' => ['Vehicles (assets)', ['default_tracking_mode' => 'serial']], 'TYRES' => 'Tyres & Tubes', 'BATTERIES' => 'Batteries (vehicle)',
                'ACCESSORIES' => 'Vehicle Accessories', 'FLEET_CONSUMABLES' => 'Fleet Consumables']],
            'FIN' => ['Finished Goods', [], ['OWN_MANUFACTURED' => 'Own-Manufactured Products', 'ASSEMBLED_KITS' => 'Assembled Kits', 'PRIVATE_LABEL' => 'Private Label',
                'TRADING' => 'Trading Goods', 'REFURBISHED' => 'Returned-Refurbished']],
            'WIP' => ['Work in Progress & Semi-Finished', [], ['SUB_ASSEMBLIES' => 'Sub-assemblies', 'SEMI_FINISHED' => 'Semi-finished Components', 'REWORK' => 'Rework Items']],
            'SERV' => ['Services & Non-Stock', [], ['MAINTENANCE' => 'Maintenance Services', 'TRANSPORT' => 'Transport Services', 'SUBSCRIPTIONS' => 'Subscriptions', 'FEES' => 'Fees & Charges']],
        ];
    }

    public function name(): string
    {
        return 'category_taxonomy';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $groups = $options['groups'] ?? null;
        $storageCategories = DB::table('storage_categories')->pluck('id', 'code');
        $inserted = 0;
        $order = 0;
        foreach (self::data() as $topCode => [$topName, $topPolicy, $subs]) {
            if (is_array($groups) && ! in_array($topCode, $groups, true)) {
                continue;
            }
            $topId = $this->upsert(null, $topCode, $topName, $topName, $topCode, 0, $order += 10, $topPolicy, $storageCategories, $inserted);
            $subOrder = 0;
            $subs['OTHER'] = 'Other';
            foreach ($subs as $subCode => $sub) {
                [$subName, $policy] = is_array($sub) ? $sub : [$sub, []];
                $code = $topCode.'.'.$subCode;
                $this->upsert($topId, $code, $subName, $topName.ItemCategory::PATH_SEPARATOR.$subName, $topCode.'/'.$code, 1, $subOrder += 10, $policy, $storageCategories, $inserted);
            }
        }

        return ['inserted' => $inserted];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  \Illuminate\Support\Collection<string, string>  $storageCategories
     */
    private function upsert(?string $parentId, string $code, string $name, string $path, string $codePath, int $depth, int $order, array $policy, $storageCategories, int &$inserted): string
    {
        $existing = DB::table('item_categories')->where('code', $code)->value('id');
        if ($existing !== null) {
            return $existing;
        }
        foreach (['default_tracking_mode' => TrackingMode::class, 'default_expiry_policy' => ExpiryPolicy::class,
            'default_removal_strategy' => RemovalStrategy::class, 'default_qc_policy' => QcPolicy::class] as $field => $enum) {
            if (isset($policy[$field])) {
                $enum::from($policy[$field]); // fail fast on a bad seed value
            }
        }
        $id = (string) Str::uuid7();
        DB::table('item_categories')->insert([
            'id' => $id, 'parent_id' => $parentId, 'code' => $code, 'name' => $name, 'full_path' => $path, 'code_path' => $codePath,
            'depth' => $depth, 'sort_order' => $order, 'status' => 'active',
            'default_tracking_mode' => $policy['default_tracking_mode'] ?? null,
            'default_expiry_policy' => $policy['default_expiry_policy'] ?? null,
            'default_removal_strategy' => $policy['default_removal_strategy'] ?? null,
            'default_qc_policy' => $policy['default_qc_policy'] ?? null,
            'default_storage_category_id' => isset($policy['storage_category']) ? ($storageCategories[$policy['storage_category']] ?? null) : null,
            'is_hazardous' => $policy['is_hazardous'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $inserted++;

        return $id;
    }
}
