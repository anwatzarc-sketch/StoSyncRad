<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Services\ConfigBundleService;
use Illuminate\Console\Command;

/** `platform:bundle:export` — write this deployment's Config Bundle (Chapter 03 §3.8). */
final class PlatformBundleExportCommand extends Command
{
    protected $signature = 'platform:bundle:export {--output= : File to write (default: stdout)}';

    protected $description = 'Export the Config Bundle of this deployment.';

    public function handle(ConfigBundleService $bundles): int
    {
        $json = json_encode($bundles->export(null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $out = (string) $this->option('output');
        if ($out === '') {
            $this->line($json);

            return self::SUCCESS;
        }
        file_put_contents($out, $json.PHP_EOL);
        $this->info("Bundle written to {$out}.");

        return self::SUCCESS;
    }
}
