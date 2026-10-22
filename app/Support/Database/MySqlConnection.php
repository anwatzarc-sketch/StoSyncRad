<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection as BaseMySqlConnection;
use Illuminate\Database\Schema\MySqlBuilder;
use PDO;
use WeakMap;

/**
 * MySQL connection whose schema builder uses MySqlBlueprint and MySqlSchemaGrammar (registered in
 * PlatformServiceProvider). It also applies the session safety settings (Chapter 09 §9.7) to every
 * PDO it opens: statement and lock timeouts from `database.timeouts.<connection>`, and a read-only
 * session for reporting. MySQL has no per-account session defaults, so this is the only place they
 * are set. Applying them when the PDO is actually opened keeps boot free of database round trips
 * (a request or command that never queries never connects) and re-applies them after a reconnect.
 */
final class MySqlConnection extends BaseMySqlConnection
{
    /** @var WeakMap<PDO, true>|null PDOs whose session settings are applied */
    private ?WeakMap $configuredSessions = null;

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

    public function getPdo()
    {
        return $this->withSessionSettings(parent::getPdo());
    }

    public function getReadPdo()
    {
        return $this->withSessionSettings(parent::getReadPdo());
    }

    /** innodb_lock_wait_timeout takes whole seconds (minimum 1); a configured millisecond limit rounds up. */
    public static function lockWaitSeconds(int $milliseconds): int
    {
        return max(1, intdiv($milliseconds + 999, 1000));
    }

    private function withSessionSettings(?PDO $pdo): ?PDO
    {
        $this->configuredSessions ??= new WeakMap;
        if ($pdo === null || isset($this->configuredSessions[$pdo])) {
            return $pdo;
        }

        $settings = config('database.timeouts.'.$this->getName());
        if (is_array($settings)) {
            $statements = [];
            if (($settings['statement_timeout'] ?? 0) > 0) {
                $statements[] = 'SESSION max_execution_time = '.(int) $settings['statement_timeout'];
            }
            if (($settings['lock_timeout'] ?? 0) > 0) {
                $statements[] = 'SESSION innodb_lock_wait_timeout = '.self::lockWaitSeconds((int) $settings['lock_timeout']);
            }
            // Metadata locks (DDL vs. open transactions) otherwise wait up to a year.
            $statements[] = 'SESSION lock_wait_timeout = 30';
            $pdo->exec('SET '.implode(', ', $statements));
        }
        if ($this->getName() === 'mysql_reporting') {
            $pdo->exec('SET SESSION transaction_read_only = ON');
        }
        $this->configuredSessions[$pdo] = true;

        return $pdo;
    }
}
