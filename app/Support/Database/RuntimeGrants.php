<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Table-level privileges of the runtime account (Chapter 14 §14.5). MySQL cannot revoke one table out
 * of a database-level grant, so setup.sql grants only SELECT, INSERT and LOCK TABLES on the database
 * and this sync grants UPDATE and DELETE table by table — never on an append-only table, which only
 * receives column-level UPDATE on its registered updatable columns.
 *
 * Runs after every migration (MigrationsEnded) and in `platform:upgrade`, as the deployment account.
 * GRANT is idempotent and `REVOKE IF EXISTS` tolerates privileges that were never granted.
 */
final class RuntimeGrants
{
    /** @return int number of tables processed */
    public function sync(?string $connection = null): int
    {
        $db = DB::connection($connection);
        $app = $this->account($db->getPdo(), 'app');
        $tables = $db->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $db->getDatabaseName())->where('TABLE_TYPE', 'BASE TABLE')
            ->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();

        foreach ($tables as $table) {
            $quoted = Ddl::quoteIdentifier($table);
            if (! AppendOnlyTables::contains($table)) {
                $db->statement("GRANT UPDATE, DELETE ON $quoted TO $app");

                continue;
            }
            $db->statement("REVOKE IF EXISTS UPDATE, DELETE ON $quoted FROM $app");
            $columns = AppendOnlyTables::updatableColumns($table);
            if ($columns !== []) {
                $list = implode(', ', array_map(Ddl::quoteIdentifier(...), $columns));
                $db->statement("GRANT UPDATE ($list) ON $quoted TO $app");
            }
        }

        return count($tables);
    }

    private function account(\PDO $pdo, string $which): string
    {
        return $pdo->quote((string) config("database.accounts.$which")).'@'.$pdo->quote((string) config('database.accounts.host', '%'));
    }
}
