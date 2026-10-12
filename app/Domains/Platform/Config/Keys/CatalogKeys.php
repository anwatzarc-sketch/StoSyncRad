<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Catalog\Enums\BarcodeSymbology;
use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\LotUniquenessScope;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\RemovalStrategy;
use App\Domains\Catalog\Enums\SerialUniquenessScope;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class CatalogKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::str('catalog.sku.pattern', '{CAT}-{SEQ:6}', 'SKU pattern', 'Pattern for generated SKUs. Tokens: {CAT} category code, {SEQ:n} zero-padded sequence, {YYYY}, {YY}.', 'G', ['max_length' => 60]),
            self::bool('catalog.sku.generate_on_create', true, 'Generate SKUs', 'Generate a SKU from the pattern when none is supplied.'),
            self::bool('catalog.sku.immutable_after_posting', true, 'SKU immutable after posting', 'Block SKU changes once the item has posted movements.'),
            self::bool('catalog.items_on_non_leaf_categories', false, 'Items on parent categories', 'Allow items to be placed on categories that have children.'),
            self::enum('catalog.default_tracking_mode', TrackingMode::class, 'none', 'Default tracking mode', 'Tracking mode proposed for new items.', 'GC'),
            self::enum('catalog.default_expiry_policy', ExpiryPolicy::class, 'not_tracked', 'Default expiry policy', 'Expiry policy proposed for new items.', 'GC'),
            self::enum('catalog.default_removal_strategy', RemovalStrategy::class, 'fifo', 'Default removal strategy', 'Physical picking order used when an item does not override it.', 'GC'),
            self::enum('catalog.default_qc_policy', QcPolicy::class, 'none', 'Default QC policy', 'Receiving quality-control policy for new items.', 'GC'),
            self::enum('catalog.lot_uniqueness_scope', LotUniquenessScope::class, 'item', 'Lot uniqueness scope', 'Whether lot numbers are unique per item or per item and supplier.'),
            self::str('catalog.lot_number.pattern', '{ITEM}-{YYMMDD}-{SEQ:4}', 'Lot number pattern', 'Pattern for generated lot numbers. Tokens: {ITEM} SKU, {YYMMDD}, {SEQ:n}.', 'GCI', ['max_length' => 60]),
            self::enum('catalog.serial_uniqueness_scope', SerialUniquenessScope::class, 'item', 'Serial uniqueness scope', 'Whether serial numbers are unique per item or globally.'),
            self::str('catalog.serial_number.pattern', '{ITEM}-{SEQ:8}', 'Serial number pattern', 'Pattern for generated serial numbers.', 'GCI', ['max_length' => 60]),
            self::enum('catalog.barcode.default_symbology', BarcodeSymbology::class, 'code128', 'Default barcode symbology', 'Symbology for generated barcodes and labels.'),
            self::bool('catalog.barcode.generate_on_create', false, 'Generate barcodes', 'Generate an internal barcode for new items without one.'),
            self::bool('catalog.variants.enabled', true, 'Variants', 'Enable item attributes and variants.'),
            self::json('catalog.abc.thresholds', ['a' => '0.80', 'b' => '0.95'], 'ABC thresholds', 'Cumulative value share boundaries for A and B classes.'),
            self::int('catalog.abc.recalculate_days', 30, 'ABC recalculation interval', 'Days between automatic ABC classification runs.', 'G', 1, 365),
            self::int('catalog.max_images_per_item', 10, 'Images per item', 'Maximum number of images per item.', 'G', 1, 50),
        ];
    }
}
