<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use Illuminate\Support\Str;

/**
 * Optional role templates (Chapter 04 §4.5). Imported roles are ordinary, editable client data —
 * the code never depends on them. Templates are permission-code patterns (wildcards allowed)
 * resolved against the registry at import time. Options: templates => [codes] (default: all).
 */
final class RoleTemplatesSeed extends Seed
{
    /** code → [name, description, include patterns, exclude patterns] */
    public static function templates(): array
    {
        $approve = ['*.approve', '*.reject', '*.read', '*.read_own', 'audit.approval.read', 'reporting.catalog.read', 'reporting.report.run'];

        return [
            'platform_administrator' => ['Platform Administrator', 'Configuration, branding, users, roles and organization structure.',
                ['platform.*', 'identity.*', 'organization.*', 'audit.event.read', 'messaging.*', 'exchange.*', 'reporting.catalog.read', 'reporting.report.run'],
                ['platform.setup.reopen', 'platform.bundle.import']],
            'inventory_manager' => ['Inventory Manager', 'Company-wide stock control, valuation, adjustments and planning.',
                ['catalog.*', 'inventory.*', 'planning.*', 'stocktaking.*', 'transfers.*', 'receiving.*.read', 'procurement.*.read', 'fulfillment.*.read',
                    'organization.*.read', 'partners.*.read', 'reporting.*', 'audit.approval.read', 'exchange.import.*'],
                ['inventory.reconciliation.repair', 'reporting.definition.manage_system', 'reporting.personal_fields.view', 'reporting.restricted_fields.view', 'inventory.negative_stock.override', 'inventory.expired_issue.override']],
            'procurement_officer' => ['Procurement Officer', 'Purchase requests and orders, suppliers and vendor pricing.',
                ['procurement.purchase_request.*', 'procurement.purchase_order.*', 'procurement.supplier_return.read', 'procurement.supplier_return.create',
                    'procurement.supplier_return.update', 'procurement.supplier_return.submit', 'procurement.asn.*', 'partners.*', 'catalog.item.read',
                    'catalog.vendor_pricelist.*', 'catalog.reorder_rule.read', 'planning.replenishment.*', 'planning.demand.read', 'inventory.stock.read',
                    'receiving.goods_receipt.read', 'reporting.catalog.read', 'reporting.report.run', 'reporting.report.export_xlsx', 'reporting.definition.*'],
                ['procurement.purchase_order.approve', 'procurement.purchase_request.approve', 'partners.partner.blacklist', 'reporting.definition.share', 'reporting.definition.manage_system']],
            'warehouse_manager' => ['Warehouse Manager', 'Runs assigned warehouses: receiving, transfers, issues, counts.',
                ['organization.warehouse.read', 'organization.storage_location.*', 'organization.putaway_rule.*', 'receiving.*', 'transfers.*', 'fulfillment.*',
                    'stocktaking.*', 'inventory.stock.read', 'inventory.movement.read', 'inventory.reservation.*', 'inventory.lot.*', 'inventory.serial.*',
                    'inventory.relocation.*', 'inventory.condition.change', 'inventory.quarantine.release', 'inventory.scrap.*', 'inventory.trace.read',
                    'catalog.item.read', 'catalog.barcode.read', 'catalog.label.print', 'catalog.reorder_rule.*', 'planning.replenishment.*', 'planning.demand.read',
                    'audit.approval.read', 'reporting.catalog.read', 'reporting.report.run', 'reporting.report.export_pdf', 'reporting.report.export_xlsx', 'reporting.definition.*'],
                ['transfers.discrepancy.accept', 'receiving.over_receipt.override', 'reporting.definition.share', 'reporting.definition.manage_system', 'transfers.transfer.read_company_wide']],
            'storekeeper' => ['Storekeeper / Warehouse Operator', 'Receives, picks, dispatches, receives transfers and counts stock.',
                ['receiving.goods_receipt.read', 'receiving.goods_receipt.create', 'receiving.goods_receipt.update', 'receiving.goods_receipt.submit', 'receiving.qc.inspect',
                    'receiving.putaway.*', 'transfers.transfer.read', 'transfers.transfer.pick', 'transfers.transfer.dispatch', 'transfers.transfer.receive',
                    'transfers.discrepancy.read', 'fulfillment.pick_list.*', 'fulfillment.stock_issue.read', 'fulfillment.stock_issue.create', 'fulfillment.stock_issue.update',
                    'fulfillment.stock_issue.submit', 'fulfillment.stock_request.read', 'fulfillment.internal_return.read', 'fulfillment.internal_return.create',
                    'fulfillment.internal_return.inspect', 'fulfillment.shipment.read', 'fulfillment.shipment.pack', 'stocktaking.stocktake.read', 'stocktaking.stocktake.count',
                    'stocktaking.stocktake.recount', 'inventory.stock.read', 'inventory.movement.read', 'inventory.lot.read', 'inventory.lot.register', 'inventory.serial.read',
                    'inventory.serial.register', 'inventory.relocation.*', 'catalog.item.read', 'catalog.barcode.read', 'catalog.label.print'], []],
            'stock_requester' => ['Stock Requester', 'Requests stock and tracks own requests.',
                ['fulfillment.stock_request.read_own', 'fulfillment.stock_request.create_own', 'fulfillment.stock_request.cancel_own', 'catalog.item.read', 'inventory.stock.read'], []],
            'approver' => ['Approver', 'Approves or rejects documents within assigned limits.', $approve,
                ['*.read_company_wide', 'identity.*', 'platform.*', 'audit.event.read', 'inventory.valuation.read']],
            'auditor' => ['Auditor / Read-only', 'Reads every record, audit trail and report; cannot change anything.',
                ['*.read', '*.read_own', '*.read_company_wide', 'audit.*', 'identity.security_event.read', 'reporting.catalog.read', 'reporting.report.*', 'reporting.run.read_all',
                    'reporting.cost_fields.view', 'reporting.definition.read', 'reporting.definition.create', 'reporting.definition.update', 'reporting.definition.clone'], ['reporting.report.cancel_own']],
            'executive' => ['Executive', 'Company-wide dashboards, valuation and performance reporting.',
                ['inventory.stock.read', 'inventory.stock.read_company_wide', 'inventory.valuation.read', 'inventory.valuation.read_company_wide', 'transfers.transfer.read_company_wide',
                    'planning.demand.read', 'planning.allocation_plan.read', 'reporting.catalog.read', 'reporting.report.*', 'reporting.definition.*', 'reporting.cost_fields.view'],
                ['reporting.definition.manage_system']],
            'reporting_analyst' => ['Reporting Analyst', 'Builds, shares and exports custom reports.',
                ['reporting.*', 'inventory.stock.read', 'inventory.stock.read_company_wide'], ['reporting.definition.manage_system', 'reporting.personal_fields.view', 'reporting.restricted_fields.view']],
        ];
    }

    public function __construct(private readonly PermissionRegistry $registry, private readonly SaveRoleAction $saveRole) {}

    public function name(): string
    {
        return 'role_templates';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $only = $options['templates'] ?? null;
        $inserted = 0;
        foreach (self::templates() as $code => [$name, $description, $include, $exclude]) {
            if ((is_array($only) && ! in_array($code, $only, true)) || Role::query()->where('code', $code)->exists()) {
                continue;
            }
            $this->saveRole->execute(['code' => $code, 'name' => $name, 'description' => $description,
                'permissions' => $this->resolve($include, $exclude)], null, null, fromTemplate: true);
            $inserted++;
        }

        return ['inserted' => $inserted];
    }

    /**
     * @param  array<int, string>  $include
     * @param  array<int, string>  $exclude
     * @return array<int, string>
     */
    public function resolve(array $include, array $exclude): array
    {
        return $this->registry->all()->keys()->filter(function (string $code) use ($include, $exclude) {
            foreach ($exclude as $pattern) {
                if (Str::is($pattern, $code)) {
                    return false;
                }
            }
            foreach ($include as $pattern) {
                if (Str::is($pattern, $code)) {
                    return true;
                }
            }

            return false;
        })->values()->all();
    }
}
