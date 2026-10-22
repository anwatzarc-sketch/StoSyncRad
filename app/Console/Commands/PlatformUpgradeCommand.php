<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Services\SetupState;
use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use App\Domains\ReportingEngine\Services\ReportingSetup;
use App\Support\Database\RuntimeGrants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * `platform:upgrade` (Chapter 02 §2.6, Chapter 16 §16.3 step 5): after `migrate`, bring a deployment
 * to this release — permissions, configuration registry, reporting views and dataset catalog,
 * additive seeds (client edits win), then the route gates and the reporting-role probe.
 */
final class PlatformUpgradeCommand extends Command
{
    protected $signature = 'platform:upgrade';

    protected $description = 'Apply this release\'s permissions, reporting catalog and additive seeds.';

    public function handle(PermissionSyncer $permissions, DatasetCatalog $catalog, ReportingSetup $reporting, SeedRunner $seeds, SetupState $setup, RuntimeGrants $grants): int
    {
        $result = $permissions->sync();
        $this->line('✓ Permissions synced '.json_encode($result));

        if (Artisan::call('config:registry:validate') !== self::SUCCESS) {
            $this->error(trim(Artisan::output()));

            return self::FAILURE;
        }
        $this->line('✓ Configuration registry valid');

        $views = $catalog->installViews('mysql_deploy');
        $tables = $grants->sync('mysql_deploy');
        $this->line("✓ Runtime account grants synced ($tables tables)");
        $synced = $reporting->syncDatasets();
        $this->line("✓ Reporting views installed ($views); datasets synced ".json_encode($synced));

        if ($setup->isComplete()) {
            foreach ($seeds->runAll(SeedRunner::core()) as $name => $counts) {
                $this->line("✓ Seed $name ".json_encode($counts));
            }
            $this->line('✓ Standard reports '.json_encode($reporting->seedStandardReports()));
        } else {
            $this->comment('· Seeds deferred: Platform Setup runs them.');
        }

        foreach (['permissions:audit-routes', 'schema:audit-routes'] as $gate) {
            if (Artisan::call($gate) !== self::SUCCESS) {
                $this->error("$gate failed:");
                $this->line(Artisan::output());

                return self::FAILURE;
            }
        }
        $this->line('✓ Route gates passed');

        $probe = $reporting->probeReadOnly();
        if (! ($probe['read_only'] ?? false)) {
            $this->error('Reporting account is not read-only: '.json_encode($probe));

            return self::FAILURE;
        }
        $this->line('✓ Reporting account read-only');
        $this->info('Upgrade complete. Next: config/route/view/event cache, queue:restart, platform:health --strict.');

        return self::SUCCESS;
    }
}
