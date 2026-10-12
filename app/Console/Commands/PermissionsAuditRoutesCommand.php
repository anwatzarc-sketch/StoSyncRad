<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use Illuminate\Console\Command;

/** CI gate: every route has exactly one permission or is explicitly public (Chapter 04 §4.4). */
final class PermissionsAuditRoutesCommand extends Command
{
    protected $signature = 'permissions:audit-routes {--strict : also fail on declared permissions bound to no route}';

    protected $description = 'Fail when a route lacks a permission binding or references an undeclared permission.';

    public function handle(PermissionSyncer $syncer): int
    {
        $audit = $syncer->audit();
        $failed = false;

        if ($audit['unbound_routes'] !== []) {
            $failed = true;
            $this->error('Routes without a permission binding:');
            $this->table(['Method', 'Name', 'URI'], array_map(fn ($r) => [$r['method'], $r['name'] ?? '-', $r['uri']], $audit['unbound_routes']));
        }
        if ($audit['undeclared'] !== []) {
            $failed = true;
            $this->error('Routes bound to undeclared permissions: '.implode(', ', $audit['undeclared']));
        }
        if ($audit['orphaned'] !== []) {
            $this->warn('Declared permissions bound to no route: '.implode(', ', $audit['orphaned']));
            $failed = $failed || (bool) $this->option('strict');
        }
        if (! $failed) {
            $this->info('Route permission audit passed.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
