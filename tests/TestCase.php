<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Enums\EnumLabelResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Base test case. The PostgreSQL test schema is (re)built once per PHP process with the
 * deployment role; each test then runs inside a transaction on the runtime connection that is
 * rolled back afterwards.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as traitBeginDatabaseTransaction;
    }

    /** @var array<int, string> */
    protected $connectionsToTransact = ['pgsql'];

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PlatformConfig::class)->forget();
        $this->app->make(EnumLabelResolver::class)->forget();
    }

    /** Build the schema once per process, then open the per-test transaction. */
    public function beginDatabaseTransaction(): void
    {
        self::migrateOnce();
        $this->traitBeginDatabaseTransaction();
    }

    /**
     * For test classes that must COMMIT (e.g. reporting runs read through a separate read-only
     * session): rebuild the schema before the next class so committed rows never leak.
     */
    protected static function rebuildSchemaAfterwards(): void
    {
        self::$migrated = false;
    }

    public static function migrateOnce(): void
    {
        if (self::$migrated) {
            return;
        }
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_deploy', '--force' => true]);
        DB::connection('pgsql_deploy')->disconnect();
        self::$migrated = true;
    }
}
