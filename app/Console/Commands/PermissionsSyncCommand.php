<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use Illuminate\Console\Command;

final class PermissionsSyncCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Synchronize the permission registry, implications and endpoint bindings from code (idempotent).';

    public function handle(PermissionSyncer $syncer): int
    {
        $result = $syncer->sync();
        $this->info(sprintf('Permissions: %d declared, %d inserted, %d newly deprecated, %d endpoint bindings.',
            $result['declared'], $result['inserted'], $result['deprecated'], $result['bindings']));

        return self::SUCCESS;
    }
}
