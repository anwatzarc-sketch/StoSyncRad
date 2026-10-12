<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;

/**
 * Application navigation (Chapter 12 §12.4). The server decides which entries a person sees —
 * permission held in some scope, feature enabled (route feature and the permission's own feature);
 * the client only renders what it receives. Resource entries resolve their URL, read permission
 * and feature from ResourceRegistry.
 */
final class Navigation
{
    /** @var array<int, array{key: string, label: string, icon: string, items: array<int, array<string, string>>}> */
    private const SECTIONS = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'home', 'items' => [
            ['label' => 'Dashboard', 'href' => '/', 'icon' => 'home'],
            ['label' => 'Scan', 'href' => '/scan', 'permission' => 'catalog.item.read', 'icon' => 'scan'],
            ['label' => 'Notifications', 'href' => '/notifications', 'permission' => 'messaging.notification.read_own', 'icon' => 'bell'],
        ]],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'boxes', 'items' => [
            ['label' => 'Stock', 'href' => '/stock', 'permission' => 'inventory.stock.read', 'icon' => 'boxes'],
            ['label' => 'Movements', 'resource' => 'movements'],
            ['label' => 'Lots', 'resource' => 'lots'],
            ['label' => 'Serials', 'resource' => 'serials'],
            ['label' => 'Reservations', 'resource' => 'reservations'],
            ['label' => 'Traceability', 'href' => '/trace', 'permission' => 'inventory.trace.read', 'icon' => 'route'],
            ['label' => 'Recalls', 'resource' => 'recalls'],
            ['label' => 'Reconciliation', 'resource' => 'reconciliation-runs'],
            ['label' => 'Valuation', 'href' => '/valuation', 'permission' => 'inventory.valuation.read', 'icon' => 'coins'],
        ]],
        ['key' => 'planning', 'label' => 'Planning', 'icon' => 'chart', 'items' => [
            ['label' => 'Replenishment', 'href' => '/planning/replenishment', 'permission' => 'planning.replenishment.read', 'icon' => 'refresh'],
            ['label' => 'Reorder rules', 'resource' => 'reorder-rules'],
            ['label' => 'Allocation plans', 'resource' => 'allocation-plans'],
            ['label' => 'Forecast inputs', 'resource' => 'forecast-inputs'],
        ]],
        ['key' => 'procurement', 'label' => 'Procurement', 'icon' => 'cart', 'items' => [
            ['label' => 'Purchase requests', 'resource' => 'purchase-requests'],
            ['label' => 'Purchase orders', 'resource' => 'purchase-orders'],
            ['label' => 'Shipment notices', 'resource' => 'asns'],
            ['label' => 'Supplier returns', 'resource' => 'supplier-returns'],
            ['label' => 'Vendor price lists', 'resource' => 'vendor-pricelists'],
        ]],
        ['key' => 'receiving', 'label' => 'Receiving', 'icon' => 'inbox', 'items' => [
            ['label' => 'Goods receipts', 'resource' => 'goods-receipts'],
            ['label' => 'Put-away tasks', 'resource' => 'putaway-tasks'],
        ]],
        ['key' => 'transfers', 'label' => 'Transfers', 'icon' => 'truck', 'items' => [
            ['label' => 'Transfers', 'resource' => 'transfers'],
            ['label' => 'Discrepancies', 'resource' => 'transfer-discrepancies'],
        ]],
        ['key' => 'operations', 'label' => 'Stock operations', 'icon' => 'clipboard', 'items' => [
            ['label' => 'Stock requests', 'resource' => 'stock-requests'],
            ['label' => 'Stock issues', 'resource' => 'stock-issues'],
            ['label' => 'Internal returns', 'resource' => 'internal-returns'],
            ['label' => 'Opening balances', 'resource' => 'opening-balances'],
            ['label' => 'Relocations', 'resource' => 'relocations'],
            ['label' => 'Condition changes', 'resource' => 'condition-changes'],
            ['label' => 'Scrap', 'resource' => 'scrap-documents'],
        ]],
        ['key' => 'sales', 'label' => 'Sales & fulfillment', 'icon' => 'bag', 'items' => [
            ['label' => 'Sales orders', 'resource' => 'sales-orders'],
            ['label' => 'Pick lists', 'resource' => 'pick-lists'],
            ['label' => 'Shipments', 'resource' => 'shipments'],
            ['label' => 'Customer returns', 'resource' => 'customer-returns'],
        ]],
        ['key' => 'stocktaking', 'label' => 'Stocktaking', 'icon' => 'list-check', 'items' => [
            ['label' => 'Stocktakes', 'resource' => 'stocktakes'],
            ['label' => 'Adjustments', 'resource' => 'stock-adjustments'],
        ]],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'tag', 'items' => [
            ['label' => 'Items', 'resource' => 'items'],
            ['label' => 'Categories', 'resource' => 'item-categories'],
            ['label' => 'Variants', 'resource' => 'item-variants'],
            ['label' => 'Units of measure', 'resource' => 'units'],
            ['label' => 'Unit categories', 'resource' => 'uom-categories'],
            ['label' => 'Attributes', 'resource' => 'item-attributes'],
            ['label' => 'Tags', 'resource' => 'tags'],
            ['label' => 'QC templates', 'resource' => 'qc-templates'],
        ]],
        ['key' => 'partners', 'label' => 'Partners', 'icon' => 'users', 'items' => [
            ['label' => 'Partners', 'resource' => 'partners'],
            ['label' => 'Carriers', 'href' => '/carriers', 'permission' => 'partners.carrier.read', 'icon' => 'truck'],
        ]],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'items' => [
            ['label' => 'Reports', 'href' => '/reports', 'permission' => 'reporting.report.run', 'icon' => 'chart'],
            ['label' => 'Report runs', 'href' => '/reports/runs', 'permission' => 'reporting.report.run', 'icon' => 'history'],
            ['label' => 'Imports', 'href' => '/imports', 'permission' => 'exchange.import.read', 'icon' => 'upload'],
        ]],
        ['key' => 'administration', 'label' => 'Administration', 'icon' => 'settings', 'items' => [
            ['label' => 'Accounts', 'resource' => 'accounts'],
            ['label' => 'People', 'resource' => 'persons'],
            ['label' => 'Roles', 'resource' => 'roles'],
            ['label' => 'Role assignments', 'resource' => 'role-assignments'],
            ['label' => 'Segregation of duties', 'resource' => 'sod-rules'],
            ['label' => 'Approval policies', 'resource' => 'approval-policies'],
            ['label' => 'Branches', 'resource' => 'branches'],
            ['label' => 'Warehouses', 'resource' => 'warehouses'],
            ['label' => 'Storage locations', 'resource' => 'storage-locations'],
            ['label' => 'Storage categories', 'resource' => 'storage-categories'],
            ['label' => 'Put-away rules', 'resource' => 'putaway-rules'],
            ['label' => 'Company', 'href' => '/admin/company', 'permission' => 'platform.company.read', 'icon' => 'building'],
            ['label' => 'Configuration', 'href' => '/admin/config', 'permission' => 'platform.config.read', 'icon' => 'sliders'],
            ['label' => 'Brand', 'href' => '/admin/brand', 'permission' => 'platform.brand.read', 'icon' => 'palette'],
            ['label' => 'Templates', 'href' => '/admin/templates', 'permission' => 'platform.template.read', 'icon' => 'file'],
            ['label' => 'Lookups', 'href' => '/admin/lookups', 'permission' => 'platform.lookup.read', 'icon' => 'list'],
            ['label' => 'Numbering', 'href' => '/admin/numbering', 'permission' => 'platform.numbering.manage', 'icon' => 'hash'],
            ['label' => 'Config bundles', 'href' => '/admin/bundles', 'permission' => 'platform.bundle.export', 'icon' => 'package'],
            ['label' => 'Webhooks', 'href' => '/admin/webhooks', 'permission' => 'messaging.webhook.read', 'icon' => 'link'],
            ['label' => 'API tokens', 'href' => '/admin/api-tokens', 'permission' => 'identity.api_token.read', 'icon' => 'key'],
            ['label' => 'Audit log', 'href' => '/admin/audit', 'permission' => 'audit.event.read', 'icon' => 'shield'],
            ['label' => 'Security events', 'href' => '/admin/security-events', 'permission' => 'identity.security_event.read', 'icon' => 'shield'],
            ['label' => 'System health', 'href' => '/admin/health', 'permission' => 'platform.health.read', 'icon' => 'pulse'],
        ]],
    ];

    public function __construct(
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly PermissionRegistry $permissions,
    ) {}

    /** @return array<int, array{key: string, label: string, icon: string, items: array<int, array{label: string, href: string, icon: string}>}> */
    public function for(UserAccount $account): array
    {
        $out = [];
        foreach (self::SECTIONS as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                $resolved = $this->resolve($item);
                if ($resolved !== null && $this->visible($account, $resolved['permission'], $resolved['feature'])) {
                    $items[] = ['label' => $item['label'], 'href' => $resolved['href'], 'icon' => $item['icon'] ?? $section['icon']];
                }
            }
            if ($items !== []) {
                $out[] = ['key' => $section['key'], 'label' => $section['label'], 'icon' => $section['icon'], 'items' => $items];
            }
        }

        return $out;
    }

    /** @return array{href: string, permission: ?string, feature: ?string}|null */
    private function resolve(array $item): ?array
    {
        if (isset($item['resource'])) {
            $all = ResourceRegistry::all();
            $cfg = $all[$item['resource']] ?? null;

            return $cfg === null ? null : ['href' => '/r/'.$item['resource'], 'permission' => $cfg['read'], 'feature' => $cfg['feature'] ?? null];
        }

        return ['href' => $item['href'], 'permission' => $item['permission'] ?? null, 'feature' => $item['feature'] ?? null];
    }

    private function visible(UserAccount $account, ?string $permission, ?string $feature): bool
    {
        if ($feature !== null && ! $this->config->feature($feature)) {
            return false;
        }
        if ($permission === null) {
            return true;
        }
        $flag = $this->permissions->get($permission)?->featureFlag;
        if ($flag !== null && ! $this->config->feature($flag)) {
            return false;
        }

        return $this->access->hasPermissionAnywhere($account, $permission);
    }
}
