<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Session safety settings (Chapter 09 §9.7) on MySQL connections: applied to every PDO a connection
 * opens, and never by merely resolving a connection — booting the application (e.g. a cache store
 * that names the connection) must not need a reachable database.
 */
final class ConnectionSessionSettingsTest extends TestCase
{
    public function test_resolving_a_connection_does_not_open_it(): void
    {
        DB::purge('mysql_audit');

        $this->assertInstanceOf(Closure::class, DB::connection('mysql_audit')->getRawPdo());
    }

    public function test_session_limits_apply_to_the_first_pdo_and_again_after_a_reconnect(): void
    {
        DB::purge('mysql_audit');
        $connection = DB::connection('mysql_audit');
        $expected = ['statement_ms' => 5000, 'lock_wait_s' => 2, 'metadata_lock_wait_s' => 30];

        $this->assertSame($expected, $this->sessionLimits($connection));
        $connection->reconnect();
        $this->assertSame($expected, $this->sessionLimits($connection));
    }

    /** @return array{statement_ms: int, lock_wait_s: int, metadata_lock_wait_s: int} */
    private function sessionLimits(Connection $connection): array
    {
        $row = (array) $connection->selectOne('SELECT @@SESSION.max_execution_time AS statement_ms, @@SESSION.innodb_lock_wait_timeout AS lock_wait_s,
            @@SESSION.lock_wait_timeout AS metadata_lock_wait_s');

        return array_map('intval', $row);
    }
}
