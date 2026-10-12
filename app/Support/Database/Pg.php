<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Support\Enums\SystemEnum;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL migration helpers (Chapter 05 §5.2, Chapter 13 §13.9).
 *
 * Every helper is idempotent so migrations can be re-run in CI parity tests.
 */
final class Pg
{
    public const APP_ROLE = 'stosyncrad_app';

    public const REPORTING_ROLE = 'stosyncrad_reporting';

    /** Standard UUID v7 primary key column. */
    public static function uuidPrimary(Blueprint $table): void
    {
        // Explicit (not fluent) primary key so it is emitted before any self-referencing FK.
        $table->uuid('id');
        $table->primary('id');
    }

    /** created_at/updated_at as timestamptz. */
    public static function timestamps(Blueprint $table): void
    {
        $table->timestampTz('created_at')->nullable();
        $table->timestampTz('updated_at')->nullable();
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
        $name = self::enumConstraintName($table, $column);
        $values = implode(', ', array_map(static fn (string $v) => DB::getPdo()->quote($v), $enumClass::values()));

        DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
        DB::statement("ALTER TABLE \"$table\" ADD CONSTRAINT \"$name\" CHECK (\"$column\" IS NULL OR \"$column\" IN ($values))");
    }

    /**
     * Declare a varchar[] column whose elements must all be cases of a SystemEnum.
     *
     * @param  class-string<SystemEnum>  $enumClass
     */
    public static function enumArray(string $table, string $column, string $enumClass, bool $nullable = false): void
    {
        $null = $nullable ? '' : " NOT NULL DEFAULT '{}'::varchar[]";
        DB::statement("ALTER TABLE \"$table\" ADD COLUMN IF NOT EXISTS \"$column\" varchar(64)[]$null");
        self::enumArrayCheck($table, $column, $enumClass);
    }

    /** @param  class-string<SystemEnum>  $enumClass */
    public static function enumArrayCheck(string $table, string $column, string $enumClass): void
    {
        $name = self::enumConstraintName($table, $column);
        $values = implode(', ', array_map(static fn (string $v) => DB::getPdo()->quote($v), $enumClass::values()));
        DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
        DB::statement("ALTER TABLE \"$table\" ADD CONSTRAINT \"$name\" CHECK (\"$column\" IS NULL OR \"$column\" <@ ARRAY[$values]::varchar[])");
    }

    public static function enumConstraintName(string $table, string $column): string
    {
        return "chk_{$table}_{$column}_enum";
    }

    /** Add a named CHECK constraint (idempotent). */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE \"$table\" DROP CONSTRAINT IF EXISTS \"$name\"");
        DB::statement("ALTER TABLE \"$table\" ADD CONSTRAINT \"$name\" CHECK ($expression)");
    }

    /**
     * Make a table append-only for ordinary application paths:
     *  - revoke UPDATE/DELETE/TRUNCATE from the runtime role
     *  - BEFORE UPDATE OR DELETE trigger raising APPEND_ONLY_VIOLATION unless the deployment
     *    role has set the app.bypass_append_only GUC for an approved migration.
     *
     * @param  array<int, string>  $updatableColumns  columns that may change through the
     *                                                controlled SECURITY DEFINER path
     */
    public static function appendOnly(string $table, array $updatableColumns = []): void
    {
        self::ensureAppendOnlyFunction();

        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON \"$table\" FROM ".self::APP_ROLE);

        // Trigger arguments are always string literals; pass a PostgreSQL array literal.
        $args = $updatableColumns === []
            ? ''
            : DB::getPdo()->quote('{'.implode(',', $updatableColumns).'}');

        DB::statement("DROP TRIGGER IF EXISTS trg_{$table}_append_only ON \"$table\"");
        DB::statement(
            "CREATE TRIGGER trg_{$table}_append_only BEFORE UPDATE OR DELETE ON \"$table\" ".
            "FOR EACH ROW EXECUTE FUNCTION platform_append_only_guard($args)"
        );
    }

    /**
     * Re-grant UPDATE on specific columns of an append-only table to the runtime role. Used for
     * the controlled reversal markers on the ledger (Chapter 13 §13.9); the append-only trigger
     * independently rejects changes to any other column.
     *
     * @param  array<int, string>  $columns
     */
    public static function grantColumnUpdate(string $table, array $columns): void
    {
        $cols = implode(', ', array_map(static fn (string $c) => "\"$c\"", $columns));
        DB::statement("GRANT UPDATE ($cols) ON \"$table\" TO ".self::APP_ROLE);
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
        $t->timestampTz('submitted_at')->nullable();
        $t->uuid('approved_by')->nullable();
        $t->timestampTz('approved_at')->nullable();
        $t->uuid('posted_by')->nullable();
        $t->timestampTz('posted_at')->nullable();
        $t->uuid('cancelled_by')->nullable();
        $t->timestampTz('cancelled_at')->nullable();
        $t->uuid('cancellation_reason_lookup_value_id')->nullable();
        $t->text('cancellation_reason')->nullable();
        $t->unsignedInteger('version')->default(1);
        self::timestamps($t);
    }

    /** CHECKs shared by every document table (priority enum). */
    public static function documentChecks(string $table): void
    {
        self::enumCheck($table, 'priority', \App\Support\Enums\Priority::class);
    }

    /** Grant SELECT on a reporting view to the reporting role. */
    public static function grantReportingSelect(string $qualifiedView): void
    {
        DB::statement("GRANT SELECT ON $qualifiedView TO ".self::REPORTING_ROLE);
    }

    private static function ensureAppendOnlyFunction(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION platform_append_only_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    allowed text[] := NULL;
    col text;
    old_j jsonb;
    new_j jsonb;
BEGIN
    IF current_setting('app.bypass_append_only', true) = 'on' THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'APPEND_ONLY_VIOLATION: rows in % cannot be deleted', TG_TABLE_NAME
            USING ERRCODE = 'P0001';
    END IF;

    IF TG_NARGS > 0 THEN
        allowed := TG_ARGV[0]::text[];
        old_j := to_jsonb(OLD);
        new_j := to_jsonb(NEW);
        FOREACH col IN ARRAY allowed LOOP
            old_j := old_j - col;
            new_j := new_j - col;
        END LOOP;
        IF old_j = new_j THEN
            RETURN NEW;
        END IF;
    END IF;

    RAISE EXCEPTION 'APPEND_ONLY_VIOLATION: rows in % cannot be updated', TG_TABLE_NAME
        USING ERRCODE = 'P0001';
END;
$$;
SQL);
    }
}
