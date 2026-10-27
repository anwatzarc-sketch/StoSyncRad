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
 *
 * Two areas: `work` — the daily-operations sidebar (Home, Stock, one entry per workflow hub of
 * WorkflowCatalog, Reports), with `stock` and `reports` as the tab strips of those places — and
 * `admin`, the control plane (master data, organization, people & access, platform, oversight),
 * reached through Administration. An item's `match` lists the other URLs that belong to it.
 */
final class Navigation
{
    /** @var array<int, array{key: string, label: string, icon: string, area: string, items: array<int, array<string, mixed>>}> */
    private const SECTIONS = [
        ['key' => 'stock', 'label' => 'Stock', 'icon' => 'boxes', 'area' => 'stock', 'items' => [
            ['label' => 'Stock', 'href' => '/stock', 'permission' => 'inventory.stock.read', 'icon' => 'boxes'],
            ['label' => 'Scan', 'href' => '/scan', 'permission' => 'catalog.item.read', 'icon' => 'scan'],
            ['label' => 'Traceability', 'href' => '/trace', 'permission' => 'inventory.trace.read', 'icon' => 'route'],
            ['label' => 'Lots', 'resource' => 'lots', 'icon' => 'tag'],
            ['label' => 'Serials', 'resource' => 'serials', 'icon' => 'hash'],
            ['label' => 'Movements', 'resource' => 'movements', 'icon' => 'history'],
            ['label' => 'Reversals', 'resource' => 'movement-reversals', 'icon' => 'refresh'],
            ['label' => 'Reservations', 'resource' => 'reservations', 'icon' => 'lock'],
            ['label' => 'Recalls', 'resource' => 'recalls', 'icon' => 'alert'],
            ['label' => 'Valuation', 'href' => '/valuation', 'permission' => 'inventory.valuation.read', 'icon' => 'coins'],
        ]],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'area' => 'reports', 'items' => [
            ['label' => 'Reports', 'href' => '/reports', 'permission' => 'reporting.report.run', 'icon' => 'chart'],
            ['label' => 'Report runs', 'href' => '/reports/runs', 'permission' => 'reporting.report.run', 'icon' => 'history'],
        ]],

        // ---- Administration (control plane) ---------------------------------------------------
        ['key' => 'catalog', 'label' => 'Catalog', 'icon' => 'tag', 'area' => 'admin', 'items' => [
            ['label' => 'Items', 'resource' => 'items', 'icon' => 'package'],
            ['label' => 'Categories', 'resource' => 'item-categories', 'icon' => 'list'],
            ['label' => 'Variants', 'resource' => 'item-variants', 'icon' => 'layers'],
            ['label' => 'Attributes', 'resource' => 'item-attributes', 'icon' => 'sliders'],
            ['label' => 'Units of measure', 'resource' => 'units', 'icon' => 'hash'],
            ['label' => 'Unit categories', 'resource' => 'uom-categories', 'icon' => 'list'],
            ['label' => 'Tags', 'resource' => 'tags', 'icon' => 'tag'],
            ['label' => 'QC templates', 'resource' => 'qc-templates', 'icon' => 'list-check'],
            ['label' => 'Reorder rules', 'resource' => 'reorder-rules', 'icon' => 'refresh'],
            ['label' => 'Vendor price lists', 'resource' => 'vendor-pricelists', 'icon' => 'coins'],
            ['label' => 'Tax rates', 'resource' => 'tax-rates', 'icon' => 'coins'],
            ['label' => 'Forecast inputs', 'resource' => 'forecast-inputs', 'icon' => 'chart'],
        ]],
        ['key' => 'partners', 'label' => 'Partners', 'icon' => 'users', 'area' => 'admin', 'items' => [
            ['label' => 'Partners', 'resource' => 'partners', 'icon' => 'users'],
            ['label' => 'Carriers', 'href' => '/carriers', 'permission' => 'partners.carrier.read', 'icon' => 'truck'],
        ]],
        ['key' => 'organization', 'label' => 'Organization', 'icon' => 'building', 'area' => 'admin', 'items' => [
            ['label' => 'Branches', 'resource' => 'branches', 'icon' => 'building'],
            ['label' => 'Warehouses', 'resource' => 'warehouses', 'icon' => 'boxes'],
            ['label' => 'Storage locations', 'resource' => 'storage-locations', 'icon' => 'route'],
            ['label' => 'Storage categories', 'resource' => 'storage-categories', 'icon' => 'list'],
            ['label' => 'Put-away rules', 'resource' => 'putaway-rules', 'icon' => 'inbox'],
        ]],
        ['key' => 'access', 'label' => 'People & access', 'icon' => 'key', 'area' => 'admin', 'items' => [
            ['label' => 'Accounts', 'resource' => 'accounts', 'icon' => 'user'],
            ['label' => 'People', 'resource' => 'persons', 'icon' => 'users'],
            ['label' => 'Roles', 'resource' => 'roles', 'icon' => 'key'],
            ['label' => 'Role assignments', 'resource' => 'role-assignments', 'icon' => 'link'],
            ['label' => 'Separation of duties', 'resource' => 'sod-rules', 'icon' => 'shield'],
            ['label' => 'Approval policies', 'resource' => 'approval-policies', 'icon' => 'check'],
            ['label' => 'API tokens', 'href' => '/admin/api-tokens', 'permission' => 'identity.api_token.read', 'icon' => 'key'],
        ]],
        ['key' => 'platform', 'label' => 'Platform', 'icon' => 'settings', 'area' => 'admin', 'items' => [
            ['label' => 'Company', 'href' => '/admin/company', 'permission' => 'platform.company.read', 'icon' => 'building'],
            ['label' => 'Configuration', 'href' => '/admin/config', 'permission' => 'platform.config.read', 'icon' => 'sliders'],
            ['label' => 'Brand', 'href' => '/admin/brand', 'permission' => 'platform.brand.read', 'icon' => 'palette'],
            ['label' => 'Templates', 'href' => '/admin/templates', 'permission' => 'platform.template.read', 'icon' => 'file'],
            ['label' => 'Lookups', 'href' => '/admin/lookups', 'permission' => 'platform.lookup.read', 'icon' => 'list'],
            ['label' => 'Translations', 'href' => '/admin/translations', 'permission' => 'platform.translation.manage', 'icon' => 'globe'],
            ['label' => 'Numbering', 'href' => '/admin/numbering', 'permission' => 'platform.numbering.manage', 'icon' => 'hash'],
            ['label' => 'Config bundles', 'href' => '/admin/bundles', 'permission' => 'platform.bundle.export', 'icon' => 'package'],
            ['label' => 'Webhooks', 'href' => '/admin/webhooks', 'permission' => 'messaging.webhook.read', 'icon' => 'link'],
        ]],
        ['key' => 'data', 'label' => 'Data', 'icon' => 'upload', 'area' => 'admin', 'items' => [
            ['label' => 'Imports', 'href' => '/imports', 'permission' => 'exchange.import.read', 'icon' => 'upload'],
            ['label' => 'Opening balances', 'resource' => 'opening-balances', 'icon' => 'boxes'],
        ]],
        ['key' => 'oversight', 'label' => 'Oversight', 'icon' => 'shield', 'area' => 'admin', 'items' => [
            ['label' => 'Audit log', 'href' => '/admin/audit', 'permission' => 'audit.event.read', 'icon' => 'shield'],
            ['label' => 'Security events', 'href' => '/admin/security-events', 'permission' => 'identity.security_event.read', 'icon' => 'shield'],
            ['label' => 'System health', 'href' => '/admin/health', 'permission' => 'platform.health.read', 'icon' => 'pulse'],
            ['label' => 'Reconciliation runs', 'resource' => 'reconciliation-runs', 'icon' => 'refresh'],
            ['label' => 'Reconciliation findings', 'resource' => 'reconciliation-findings', 'icon' => 'alert'],
        ]],
    ];

    public function __construct(
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly PermissionRegistry $permissions,
        private readonly WorkQueues $work,
    ) {}

    /** @return array<int, array{key: string, label: string, icon: string, area: string, items: array<int, array{label: string, href: string, icon: string, match?: array<int, string>}>}> */
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
                $out[$section['key']] = ['key' => $section['key'], 'label' => $section['label'], 'icon' => $section['icon'], 'area' => $section['area'], 'items' => $items];
            }
        }

        return array_values([$this->work($account, $out)] + $out);
    }

    /**
     * The daily-operations sidebar: Home, Stock, the workflow hubs this person can use, Reports.
     *
     * @param  array<string, array<string, mixed>>  $sections  the resolved place sections (stock, reports, admin…)
     * @return array<string, mixed>
     */
    private function work(UserAccount $account, array $sections): array
    {
        $items = [['label' => 'Home', 'href' => '/', 'icon' => 'home']];
        if (isset($sections['stock'])) {
            $items[] = ['label' => 'Stock', 'href' => $sections['stock']['items'][0]['href'], 'icon' => 'boxes',
                'match' => array_column($sections['stock']['items'], 'href')];
        }
        foreach ($this->work->visibleHubs($account) as $key) {
            $hub = WorkflowCatalog::HUBS[$key];
            $extra = array_values(array_filter(array_column($hub['start'], 'href'), fn (string $h) => ! str_starts_with($h, '/r/')));
            $items[] = ['label' => $hub['label'], 'href' => "/work/$key", 'icon' => $hub['icon'],
                'match' => array_merge(array_map(fn (string $r) => "/r/$r", $hub['resources']), $extra)];
        }
        if (isset($sections['reports'])) {
            $items[] = ['label' => 'Reports', 'href' => $sections['reports']['items'][0]['href'], 'icon' => 'chart',
                'match' => array_column($sections['reports']['items'], 'href')];
        }

        return ['key' => 'work', 'label' => 'Work', 'icon' => 'home', 'area' => 'work', 'items' => $items];
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
