<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Support\Database\RuntimeGrants;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The runtime account's table privileges (Chapter 14 §14.5): granted table by table where the
 * deployment account can GRANT, and left alone where a hosting panel owns them (DB_MANAGE_GRANTS=false).
 */
final class RuntimeGrantsTest extends TestCase
{
    public function test_grants_changes_on_ordinary_tables_and_revokes_them_on_append_only_tables(): void
    {
        $statements = $this->privilegeStatementsDuringSync();

        $runtime = "'".config('database.accounts.app')."'@'".config('database.accounts.host')."'";
        $this->assertContains("GRANT UPDATE, DELETE ON `items` TO $runtime", $statements);
        $this->assertContains("REVOKE IF EXISTS UPDATE, DELETE ON `audit_events` FROM $runtime", $statements);
    }

    public function test_sends_no_grant_or_revoke_when_the_hosting_panel_manages_privileges(): void
    {
        config(['database.accounts.manage_grants' => false]);

        $this->assertSame([], $this->privilegeStatementsDuringSync());
    }

    /** @return array<int, string> the GRANT and REVOKE statements one sync sent */
    private function privilegeStatementsDuringSync(): array
    {
        DB::purge('mysql_deploy');   // the schema build left it disconnected
        $deploy = DB::connection('mysql_deploy');
        $deploy->enableQueryLog();
        try {
            app(RuntimeGrants::class)->sync('mysql_deploy');
        } finally {
            $deploy->disconnect();
        }

        return collect($deploy->getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^(GRANT|REVOKE)\b/', $sql) === 1)->values()->all();
    }
}
