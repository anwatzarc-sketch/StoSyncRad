<?php

declare(strict_types=1);

namespace App\Support\Database;

use LogicException;

/**
 * Ledger, audit and history tables that refuse UPDATE/DELETE (Chapter 13 §13.9). The single source for
 * the append-only triggers (Ddl::appendOnly), the runtime account's grants (RuntimeGrants) and the
 * health check (PlatformHealth).
 */
final class AppendOnlyTables
{
    /**
     * Table => columns the runtime account may still update through the controlled reversal and void
     * paths. The triggers reject a change to any other column.
     *
     * @var array<string, list<string>>
     */
    public const UPDATABLE_COLUMNS = [
        'configuration_entry_versions' => [],
        'template_versions' => [],
        'audit_events' => [],
        'security_events' => [],
        'approval_records' => [],
        'item_category_moves' => [],
        'stock_movements' => ['status', 'updated_at'],
        'stock_movement_lines' => ['reversed_by_line_id', 'reversed_quantity', 'updated_at'],
        'valuation_layer_consumptions' => [],
        'valuation_variances' => [],
        'purchase_order_revisions' => [],
        'transfer_discrepancy_resolutions' => [],
        'shipment_events' => [],
        'report_definition_versions' => [],
        'landed_cost_allocations' => [],
        'payments' => ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at'],
        'payment_allocations' => ['reversed_by', 'reversed_at', 'reversal_reason'],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::UPDATABLE_COLUMNS);
    }

    public static function contains(string $table): bool
    {
        return array_key_exists($table, self::UPDATABLE_COLUMNS);
    }

    /** @return list<string> */
    public static function updatableColumns(string $table): array
    {
        return self::UPDATABLE_COLUMNS[$table] ?? throw new LogicException("`$table` is not registered as append-only.");
    }
}
