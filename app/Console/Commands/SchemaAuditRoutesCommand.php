<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * CI gate: every unsafe route declares a TableSchema for the global validation middleware
 * (Chapter 05 §5.4), unless it is an explicit no-body operation.
 */
final class SchemaAuditRoutesCommand extends Command
{
    protected $signature = 'schema:audit-routes';

    protected $description = 'Fail when a write route has no schema declaration.';

    public function handle(Router $router): int
    {
        $missing = [];
        /** @var Route $route */
        foreach ($router->getRoutes() as $route) {
            $methods = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']);
            if ($methods === [] || $route->getAction('schema') !== null || $route->getAction('no_body') === true) {
                continue;
            }
            $name = (string) $route->getName();
            if ($name === '' || str_starts_with($name, 'setup.') || in_array($name, ['login.attempt', 'logout', 'mfa.verify', 'password.email', 'password.update'], true)
                || str_starts_with($route->uri(), '_') || str_starts_with($route->uri(), 'sanctum')) {
                continue;
            }
            $missing[] = [implode('|', $methods), $name, $route->uri()];
        }
        if ($missing !== []) {
            $this->error('Write routes without a schema declaration:');
            $this->table(['Method', 'Name', 'URI'], $missing);

            return self::FAILURE;
        }
        $this->info('Schema route audit passed.');

        return self::SUCCESS;
    }
}
