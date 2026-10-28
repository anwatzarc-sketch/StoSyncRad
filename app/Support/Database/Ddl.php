<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Enums\Priority;
use App\Support\Enums\SystemEnum;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.0.32+ migration helpers (Chapter 05 §5.2, Chapter 13 §13.9).
 *
 * Every helper is idempotent so migrations can be re-run in CI parity tests. MySQL commits each DDL
 * statement on its own, so a failed migration can leave earlier statements applied; idempotency is
 * what makes re-running it safe.
 */
final class Ddl
{
    /** MySQL identifiers (index, constraint, trigger names) are limited to 64 characters. */
    private const MAX_IDENTIFIER = 64;

    /** @var array<string, array<string, string>> table => constraint name => ADD CONSTRAINT clause */
    private static array $pendingChecks = [];

    /** An account as `'name'@'host'` for GRANT/REVOKE. */
    public static function account(string $which): string
    {
        $name = (string) config("database.accounts.$which");
        $host = (string) config('database.accounts.host', '%');

        return DB::getPdo()->quote($name).'@'.DB::getPdo()->quote($host);
    }

    public static function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** Shorten an identifier over MySQL's limit deterministically (a hash keeps it unique). */
    public static function identifier(string $name): string
    {
        return strlen($name) <= self::MAX_IDENTIFIER ? $name : substr($name, 0, self::MAX_IDENTIFIER - 9).'_'.substr(md5($name), 0, 8);
    }

    /** Standard UUID v7 primary key column (char(36); values are generated in PHP). */
    public static function uuidPrimary(Blueprint $table): void
    {
        // Explicit (not fluent) primary key so it is emitted before any self-referencing FK.
        $table->uuid('id');
        $table->primary('id');
    }

    /** created_at/updated_at as UTC DATETIME(6) (TIMESTAMP would stop at 2038). */
    public static function timestamps(Blueprint $table): void
    {
        $table->dateTime('created_at', 6)->nullable();
        $table->dateTime('updated_at', 6)->nullable();
    }

    /** created_by / updated_by actor references. */
    public static function actorColumns(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable()->index();
        $table->uuid('updated_by')->nullable();
    }

    /**
     * Declare an enum-typed column: varchar(64) + a named CHECK constraint generated from the
     * PHP enum's cases. Call enumCheck() after Schema::create() to attach the constraint.
     */
    public static function enumColumn(Blueprint $table, string $column, bool $nullable = false, ?string $default = null): void
    {
        $col = $table->string($column, 64);
        if ($nullable) {
            $col->nullable();
        }
        if ($default !== null) {
            $col->default($default);
        }
    }

    /**
     * Attach (or refresh) the CHECK constraint mirroring a SystemEnum's cases.
     *
     * @param  class-string<SystemEnum>  $enumClass
     */
    public static function enumCheck(string $table, string $column, string $enumClass): void
    {
        $col = self::quoteIdentifier($column);
        self::check($table, self::enumConstraintName($table, $column), "$col IS NULL OR $col IN (".self::quotedValues($enumClass).')');
    }

    /**
     * Declare a JSON array column whose elements must all be cases of a SystemEnum.
     *
     * @param  class-string<SystemEnum>  $enumClass
     */
    public static function enumArray(string $table, string $column, string $enumClass, bool $nullable = false): void
    {
        if (! self::columnExists($table, $column)) {
            $null = $nullable ? 'NULL' : 'NOT NULL DEFAULT (JSON_ARRAY())';
            DB::statement('ALTER TABLE '.self::quoteIdentifier($table).' ADD COLUMN '.self::quoteIdentifier($column)." JSON $null");
        }
        self::enumArrayCheck($table, $column, $enumClass);
    }

    /** @param  class-string<SystemEnum>  $enumClass */
    public static function enumArrayCheck(string $table, string $column, string $enumClass): void
    {
        $schema = json_encode(['type' => 'array', 'items' => ['enum' => array_values($enumClass::values())]], JSON_THROW_ON_ERROR);
        $col = self::quoteIdentifier($column);
        self::check($table, self::enumConstraintName($table, $column), "$col IS NULL OR JSON_SCHEMA_VALID(".DB::getPdo()->quote($schema).", $col)");
    }

    public static function enumConstraintName(string $table, string $column): string
    {
        return self::identifier("chk_{$table}_{$column}_enum");
    }

    /**
     * Add a named CHECK constraint (idempotent; MySQL has no DROP CONSTRAINT IF EXISTS). Adding a CHECK
     * rebuilds the table, so constraints are queued and each table's are added in one ALTER TABLE when
     * the migration ends (flushChecks, wired to MigrationEnded in PlatformServiceProvider).
     */
    public static function check(string $table, string $name, string $expression): void
    {
        $name = self::identifier($name);
        self::dropCheckIfExists($table, $name);
        self::$pendingChecks[$table][$name] = 'ADD CONSTRAINT '.self::quoteIdentifier($name)." CHECK ($expression)";
    }

    public static function dropCheckIfExists(string $table, string $name): void
    {
        $name = self::identifier($name);
        if (isset(self::$pendingChecks[$table][$name])) {
            unset(self::$pendingChecks[$table][$name]);

            return;
        }
        if (self::constraintExists($table, $name, 'CHECK')) {
            DB::statement('ALTER TABLE '.self::quoteIdentifier($table).' DROP CHECK '.self::quoteIdentifier($name));
        }
    }

    /** Add every queued CHECK constraint, one ALTER TABLE per table. */
    public static function flushChecks(): void
    {
        $pending = self::$pendingChecks;
        self::$pendingChecks = [];
        foreach ($pending as $table => $clauses) {
            if ($clauses !== []) {
                DB::statement('ALTER TABLE '.self::quoteIdentifier($table).' '.implode(', ', $clauses));
            }
        }
    }

    /** Forget constraints queued by a migration that failed before it ended. */
    public static function discardPendingChecks(): void
    {
        self::$pendingChecks = [];
    }

    /**
     * A JSON array column (replacing a PostgreSQL array) whose elements must match a JSON Schema
     * fragment, e.g. ['type' => 'string', 'maxLength' => 60].
     *
     * @param  array<string, mixed>  $items
     */
    public static function jsonArray(string $table, string $column, array $items): void
    {
        if (! self::columnExists($table, $column)) {
            DB::statement('ALTER TABLE '.self::quoteIdentifier($table).' ADD COLUMN '.self::quoteIdentifier($column).' JSON NOT NULL DEFAULT (JSON_ARRAY())');
        }
        $schema = json_encode(['type' => 'array', 'items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::check($table, "chk_{$table}_{$column}_items", 'JSON_SCHEMA_VALID('.DB::getPdo()->quote($schema).', '.self::quoteIdentifier($column).')');
    }

    /**
     * Unique index over an SQL expression (MySQL functional key part). A partial unique index
     * (PostgreSQL `WHERE …`) is expressed as `CASE WHEN <predicate> THEN <key> END`: rows outside the
     * predicate index NULL, and NULLs never collide.
     */
    public static function uniqueExpression(string $table, string $name, string $expression): void
    {
        self::uniqueIndex($table, $name, "($expression)");
    }

    /**
     * Unique index over raw key parts: plain columns and parenthesised expressions may be mixed, e.g.
     * `user_account_id, role_id, (COALESCE(scope_id, '<sentinel>'))`.
     */
    public static function uniqueIndex(string $table, string $name, string $keyParts): void
    {
        self::dropIndexIfExists($table, $name);
        DB::statement('CREATE UNIQUE INDEX '.self::quoteIdentifier(self::identifier($name)).' ON '.self::quoteIdentifier($table)." ($keyParts)");
    }

    public static function dropIndexIfExists(string $table, string $name): void
    {
        $name = self::identifier($name);
        $exists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('INDEX_NAME', $name)->exists();
        if ($exists) {
            DB::statement('DROP INDEX '.self::quoteIdentifier($name).' ON '.self::quoteIdentifier($table));
        }
    }

    /**
     * Make a table append-only for ordinary application paths (registered in AppendOnlyTables):
     *  - the runtime account never receives UPDATE/DELETE on it (RuntimeGrants::sync), only column
     *    UPDATE on the registered updatable columns
     *  - BEFORE UPDATE and BEFORE DELETE triggers raise APPEND_ONLY_VIOLATION (SQLSTATE 45000) unless
     *    the deployment account has set @stosyncrad_bypass_append_only = 1 for an approved migration.
     * The UPDATE trigger lists the table's columns, so a migration that later adds columns to an
     * append-only table must call this again.
     */
    public static function appendOnly(string $table): void
    {
        $updatable = AppendOnlyTables::updatableColumns($table);
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all();
        $frozen = array_values(array_diff($columns, $updatable));
        $unchanged = $frozen === [] ? 'TRUE' : implode(' AND ', array_map(static function (string $c): string {
            $col = self::quoteIdentifier($c);

            return "OLD.$col <=> NEW.$col";
        }, $frozen));

        // COALESCE keeps the guard NULL-safe: an unset variable must never read as "bypass".
        $bypass = 'COALESCE(@stosyncrad_bypass_append_only, 0) = 1 AND SUBSTRING_INDEX(USER(), \'@\', 1) = '
            .DB::getPdo()->quote((string) config('database.accounts.deploy'));
        $quoted = self::quoteIdentifier($table);

        foreach (['del' => ['DELETE', 'TRUE', 'deleted'], 'upd' => ['UPDATE', "NOT ($unchanged)", 'updated']] as $suffix => [$event, $violates, $verb]) {
            $trigger = self::quoteIdentifier(self::identifier("trg_{$table}_append_only_{$suffix}"));
            $message = DB::getPdo()->quote(SqlError::APPEND_ONLY_VIOLATION.": rows in $table cannot be $verb");
            DB::unprepared("DROP TRIGGER IF EXISTS $trigger");
            DB::unprepared(
                "CREATE TRIGGER $trigger BEFORE $event ON $quoted FOR EACH ROW BEGIN ".
                "IF NOT ($bypass) AND ($violates) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = $message; END IF; END"
            );
        }
    }

    /**
     * Standard business-document header columns (Chapter 08 common header).
     * The caller adds the status enum column and its CHECK.
     */
    public static function documentColumns(Blueprint $t, bool $warehouse = true): void
    {
        $t->string('document_number', 40)->unique();
        if ($warehouse) {
            $t->uuid('warehouse_id');
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->index('warehouse_id');
        }
        $t->uuid('branch_id')->nullable()->index();
        $t->date('document_date');
        $t->string('reference', 120)->nullable();
        $t->text('notes')->nullable();
        $t->string('priority', 64)->default('normal');
        $t->uuid('created_by')->nullable();
        $t->uuid('updated_by')->nullable();
        $t->uuid('submitted_by')->nullable();
        $t->dateTime('submitted_at', 6)->nullable();
        $t->uuid('approved_by')->nullable();
        $t->dateTime('approved_at', 6)->nullable();
        $t->uuid('posted_by')->nullable();
        $t->dateTime('posted_at', 6)->nullable();
        $t->uuid('cancelled_by')->nullable();
        $t->dateTime('cancelled_at', 6)->nullable();
        $t->uuid('cancellation_reason_lookup_value_id')->nullable();
        $t->text('cancellation_reason')->nullable();
        $t->integer('version')->default(1);
        self::timestamps($t);
    }

    /** CHECKs shared by every document table (priority enum). */
    public static function documentChecks(string $table): void
    {
        self::enumCheck($table, 'priority', Priority::class);
    }

    public static function columnExists(string $table, string $column): bool
    {
        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->exists();
    }

    private static function constraintExists(string $table, string $name, string $type): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)->where('CONSTRAINT_TYPE', $type)->exists();
    }

    /** @param  class-string<SystemEnum>  $enumClass */
    private static function quotedValues(string $enumClass): string
    {
        return implode(', ', array_map(static fn (string $v) => DB::getPdo()->quote($v), $enumClass::values()));
    }
}
