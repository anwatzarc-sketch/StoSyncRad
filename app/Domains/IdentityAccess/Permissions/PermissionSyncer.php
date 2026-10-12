<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Permissions;

use App\Domains\IdentityAccess\Enums\PermissionGrantSource;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synchronizes the permissions table with code (Chapter 04 §4.4):
 *  - upserts every declared permission (by code)
 *  - marks codes no longer declared as deprecated (assignments retained but inert)
 *  - rebuilds implications and endpoint bindings
 *  - re-materializes implied grants on existing roles
 *  - ensures the hidden system role __super_admin exists
 * Idempotent: a second run changes nothing.
 */
final class PermissionSyncer
{
    public function __construct(private readonly PermissionRegistry $registry, private readonly Router $router) {}

    /** @return array{declared: int, inserted: int, deprecated: int, bindings: int} */
    public function sync(): array
    {
        return DB::transaction(function () {
            $now = now();
            $existing = DB::table('permissions')->pluck('id', 'code')->all();
            $inserted = 0;
            $ids = $existing;

            foreach ($this->registry->all() as $code => $def) {
                $row = [
                    'module' => $def->module, 'resource' => $def->resource, 'action' => $def->action, 'label' => $def->label,
                    'description' => $def->description, 'scope_kind' => $def->scopeKind->value, 'sensitivity' => $def->sensitivity->value,
                    'is_virtual' => $def->virtual, 'is_deprecated' => false, 'feature_flag' => $def->featureFlag, 'updated_at' => $now,
                ];
                if (isset($existing[$code])) {
                    DB::table('permissions')->where('id', $existing[$code])->update($row);
                } else {
                    $id = (string) Str::uuid7();
                    DB::table('permissions')->insert($row + ['id' => $id, 'code' => $code, 'created_at' => $now]);
                    $ids[$code] = $id;
                    $inserted++;
                }
            }

            $declared = $this->registry->all()->keys()->all();
            $deprecated = DB::table('permissions')->whereNotIn('code', $declared)->where('is_deprecated', false)
                ->update(['is_deprecated' => true, 'updated_at' => $now]);

            DB::table('permission_implications')->delete();
            $implications = [];
            foreach ($this->registry->all() as $code => $def) {
                foreach ($def->implies as $implied) {
                    $implications[] = ['id' => (string) Str::uuid7(), 'permission_id' => $ids[$code], 'implies_permission_id' => $ids[$implied]];
                }
            }
            foreach (array_chunk($implications, 500) as $chunk) {
                DB::table('permission_implications')->insert($chunk);
            }

            DB::table('permission_endpoint_bindings')->delete();
            $bindings = [];
            foreach ($this->registry->routeBindings($this->router) as $code => $routes) {
                if (! isset($ids[$code])) {
                    continue;
                }
                foreach ($routes as $route) {
                    $bindings[$code.$route['method'].$route['uri']] = [
                        'id' => (string) Str::uuid7(), 'permission_id' => $ids[$code], 'http_method' => $route['method'],
                        'route_name' => $route['name'], 'uri_pattern' => $route['uri'],
                    ];
                }
            }
            foreach (array_chunk(array_values($bindings), 500) as $chunk) {
                DB::table('permission_endpoint_bindings')->insert($chunk);
            }

            $this->rematerializeImplied($ids);
            $this->ensureSuperAdminRole();

            return ['declared' => count($declared), 'inserted' => $inserted, 'deprecated' => $deprecated, 'bindings' => count($bindings)];
        });
    }

    /**
     * Problems that must fail CI: routes without a permission, bindings to undeclared codes,
     * declared non-virtual codes bound to no route.
     *
     * @return array{unbound_routes: array<int, array<string,mixed>>, undeclared: array<int, string>, orphaned: array<int, string>}
     */
    public function audit(): array
    {
        $bindings = $this->registry->routeBindings($this->router);
        $declared = $this->registry->all();

        return [
            'unbound_routes' => $this->registry->unboundRoutes($this->router),
            'undeclared' => array_values(array_diff(array_keys($bindings), $declared->keys()->all())),
            'orphaned' => $declared->filter(fn (PermissionDefinition $d) => ! $d->virtual && ! isset($bindings[$d->code])
                && $d->scopeKind->value !== 'own_records' && ! $this->isGrantOnly($d->code))->keys()->values()->all(),
        ];
    }

    /** Permissions consulted inside actions/policies rather than bound to a route. */
    private function isGrantOnly(string $code): bool
    {
        return Str::endsWith($code, ['.override', '.view_cost', '.read_company_wide', '.grant_any_scope', '.view_book_qty',
            '.direct_receipt', '.direct_issue', '.accept', '.manage_cost', '.manage_system']) || Str::startsWith($code, 'platform.config.');
    }

    /** @param  array<string, string>  $ids */
    private function rematerializeImplied(array $ids): void
    {
        $roles = DB::table('role_permissions')->where('granted_via', PermissionGrantSource::DIRECT->value)
            ->get(['role_id', 'permission_code'])->groupBy('role_id');
        foreach ($roles as $roleId => $grants) {
            $direct = $grants->pluck('permission_code')->filter(fn (string $c) => $this->registry->has($c))->all();
            $expanded = $this->registry->expand($direct);
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_code')->all();
            $missing = array_diff($expanded, $have);
            $rows = array_map(fn (string $code) => [
                'id' => (string) Str::uuid7(), 'role_id' => $roleId, 'permission_id' => $ids[$code], 'permission_code' => $code,
                'granted_via' => PermissionGrantSource::IMPLIED->value, 'created_at' => now(), 'updated_at' => now(),
            ], array_values($missing));
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    private function ensureSuperAdminRole(): void
    {
        if (! Role::query()->where('code', Role::SUPER_ADMIN_CODE)->exists()) {
            $role = new Role(['code' => Role::SUPER_ADMIN_CODE, 'name' => 'Super Administrator', 'description' => 'Bootstrap account role; grants every permission.']);
            $role->forceFill(['is_system' => true, 'is_template_origin' => false, 'status' => RoleStatus::ACTIVE])->save();
        }
    }
}
