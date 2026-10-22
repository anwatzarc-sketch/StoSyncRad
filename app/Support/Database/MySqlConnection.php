<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection as BaseMySqlConnection;
use Illuminate\Database\Schema\MySqlBuilder;

/**
 * MySQL connection whose schema builder uses MySqlBlueprint and MySqlSchemaGrammar (registered in
 * PlatformServiceProvider).
 */
final class MySqlConnection extends BaseMySqlConnection
{
    protected function getDefaultSchemaGrammar(): MySqlSchemaGrammar
    {
        return new MySqlSchemaGrammar($this);
    }

    public function getSchemaBuilder(): MySqlBuilder
    {
        $builder = parent::getSchemaBuilder();
        $builder->blueprintResolver(static fn (Connection $connection, string $table, ?Closure $callback = null) => new MySqlBlueprint($connection, $table, $callback));

        return $builder;
    }
}
