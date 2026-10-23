<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Support\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Warehouse and own-record scope of a registered resource (Chapter 04 §4.6): rows outside it simply do
 * not exist for the caller. Shared by the generic resource endpoints (scoped by the read permission)
 * and the work queues (scoped by the permission of the action the queue is waiting for).
 */
final class ResourceScope
{
    public function __construct(private readonly Access $access) {}

    /**
     * @param  array<string, mixed>  $cfg  ResourceRegistry entry; `own` = [own read permission, owner column]
     * @param  string|null  $permission  permission whose warehouses apply (default: the resource's read permission)
     * @param  array<int, string>|null  $columns  warehouse columns (default: the resource's)
     */
    public function apply(Builder $query, array $cfg, UserAccount $actor, bool $mineOnly = false, ?string $permission = null, ?array $columns = null): void
    {
        $permission ??= $cfg['read'];
        $readable = $this->access->hasPermissionAnywhere($actor, $permission);
        $own = $cfg['own'] ?? null;
        if (! $readable || $mineOnly) {
            if ($own !== null && $this->access->hasPermissionAnywhere($actor, $own[0])) {
                $query->where($own[1], $actor->id);

                return;
            }
            if (! $readable) {
                throw DomainException::permissionDenied($permission);
            }
        }
        $columns ??= $cfg['warehouse'] ?? [];
        if ($columns === []) {
            return;
        }
        $allowed = $this->access->warehouseIdsFor($actor, $permission);
        if ($allowed === '*') {
            return;
        }
        $query->where(function ($w) use ($columns, $allowed, $own, $actor) {
            $ids = $allowed === [] ? ['00000000-0000-0000-0000-000000000000'] : $allowed;
            foreach ($columns as $c) {
                if (str_contains($c, '@')) {   // `fk@table.warehouse_column`: scope through the parent document
                    [$fk, $ref] = explode('@', $c, 2);
                    [$table, $column] = explode('.', $ref, 2);
                    $w->orWhereIn($fk, fn ($q) => $q->select('id')->from($table)->whereIn($column, $ids));

                    continue;
                }
                $w->orWhereIn($c, $ids);
            }
            if ($own !== null) {
                $w->orWhere($own[1], $actor->id);
            }
        });
    }
}
