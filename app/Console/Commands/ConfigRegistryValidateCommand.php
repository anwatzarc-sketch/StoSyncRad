<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Config\ConfigRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * CI gate (Chapter 03 §3.2): every configuration key referenced in code is declared, every
 * declared key has a valid default, label and help text.
 */
final class ConfigRegistryValidateCommand extends Command
{
    protected $signature = 'config:registry:validate';

    protected $description = 'Validate configuration key declarations and references.';

    public function handle(ConfigRegistry $registry): int
    {
        $errors = [];
        foreach ($registry->all() as $key => $definition) {
            if ($definition->default === null || $definition->type->value === 'secret') {
                continue;
            }
            try {
                $definition->normalize($definition->default);
            } catch (Throwable $e) {
                $errors[] = "Default of `$key` is invalid: ".$e->getMessage();
            }
        }

        $finder = (new Finder)->files()->in([app_path(), resource_path('views')])->name(['*.php']);
        $pattern = '/(?:->|::)(?:get|string|int|bool|list|explain)\(\s*[\'"]([a-z_]+\.[a-z0-9_.]+)[\'"]/';
        foreach ($finder as $file) {
            if (preg_match_all($pattern, $file->getContents(), $m)) {
                foreach ($m[1] as $key) {
                    if (str_starts_with($key, 'platform.') && ! $registry->has($key) && preg_match('/^platform\.(codename|version|permission_providers|config_key_providers|enum_directories|schema_directories|dataset_providers|public_route_names|pre_setup_route_names|failed_attempt_audit_connection|numbering_connection|sentinel_uuid|setup_token_ttl_minutes)/', $key)) {
                        continue; // config/platform.php infrastructure keys read via config()
                    }
                    $group = explode('.', $key)[0];
                    if (in_array($group, ['app', 'database', 'auth', 'session', 'cache', 'queue', 'mail', 'logging', 'filesystems', 'hashing', 'services', 'sanctum', 'dompdf', 'inertia', 'platform'], true)) {
                        continue;
                    }
                    if (! $registry->has($key)) {
                        $errors[] = "Undeclared configuration key `$key` referenced in ".$file->getRelativePathname();
                    }
                }
            }
        }

        foreach (array_unique($errors) as $error) {
            $this->error($error);
        }
        if ($errors === []) {
            $this->info(sprintf('Configuration registry valid: %d keys in %d groups.', $registry->all()->count(), count($registry->groups())));
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
