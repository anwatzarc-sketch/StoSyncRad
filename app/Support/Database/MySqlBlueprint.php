<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema blueprint that keeps generated index names within MySQL's 64-character identifier limit
 * (PostgreSQL silently truncated them). Long names are shortened deterministically with Ddl::identifier,
 * so dropping an index by its columns finds the same name.
 */
final class MySqlBlueprint extends Blueprint
{
    protected function createIndexName($type, array $columns)
    {
        return Ddl::identifier(parent::createIndexName($type, $columns));
    }
}
