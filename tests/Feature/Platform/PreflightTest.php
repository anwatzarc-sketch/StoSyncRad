<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Services\Preflight;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Environment preflight (Chapter 02 §2.4): a runtime account able to modify ledger or audit rows
 * blocks setup, unless the deployment declares that a hosting panel owns the privileges.
 */
final class PreflightTest extends TestCase
{
    public function test_least_privilege_runtime_account_passes_the_append_only_check(): void
    {
        $this->assertSame('pass', $this->appendOnlyCheck('mysql')['status']);
    }

    public function test_runtime_account_that_can_modify_ledger_rows_fails(): void
    {
        $this->assertSame('fail', $this->appendOnlyCheck('mysql_deploy')['status']);
    }

    public function test_warns_instead_of_failing_when_the_hosting_panel_manages_privileges(): void
    {
        config(['database.accounts.manage_grants' => false]);

        $check = $this->appendOnlyCheck('mysql_deploy');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('DB_MANAGE_GRANTS=false', $check['message']);
    }

    /**
     * Run the preflight with the given connection standing in for the runtime account.
     *
     * @return array{check: string, status: string, message: string, remediation: ?string}
     */
    private function appendOnlyCheck(string $runtimeConnection): array
    {
        DB::setDefaultConnection($runtimeConnection);
        try {
            $checks = collect(app(Preflight::class)->run())->keyBy('check');
        } finally {
            DB::setDefaultConnection('mysql');
            DB::connection('mysql_deploy')->disconnect();
        }

        return $checks['runtime_role_append_only'];
    }
}
