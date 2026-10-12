<?php

declare(strict_types=1);

namespace App\Domains\DataExchange;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    /** Import types (Chapter 11 §11.9). */
    public const IMPORT_TYPES = [
        'items', 'variants', 'categories', 'uom', 'barcodes', 'packagings', 'vendor_pricelists', 'reorder_rules',
        'branches', 'warehouses', 'storage_locations', 'partners', 'lots', 'opening_balances', 'roles',
    ];

    public static function module(): string
    {
        return 'exchange';
    }

    public static function moduleLabel(): string
    {
        return 'Data Import & Export';
    }

    public static function definitions(): array
    {
        $defs = [
            P::global('exchange.import.read', 'View import batches'),
        ];
        foreach (self::IMPORT_TYPES as $type) {
            $defs[] = P::global("exchange.import.{$type}", 'Import '.str_replace('_', ' ', $type), '', S::ELEVATED, ['exchange.import.read']);
        }

        return $defs;
    }
}
