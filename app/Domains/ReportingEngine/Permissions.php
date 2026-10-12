<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine;

use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'reporting';
    }

    public static function moduleLabel(): string
    {
        return 'Custom Reporting';
    }

    public static function definitions(): array
    {
        return [
            P::global('reporting.catalog.read', 'Browse the reporting catalog'),
            P::own('reporting.definition.read', 'View own and shared report definitions'),
            P::own('reporting.definition.create', 'Create report definitions'),
            P::own('reporting.definition.update', 'Update own report definitions'),
            P::own('reporting.definition.archive', 'Archive own report definitions'),
            P::own('reporting.definition.clone', 'Clone report definitions'),
            P::global('reporting.definition.share', 'Share report definitions', '', S::ELEVATED, ['reporting.definition.read']),
            P::global('reporting.definition.manage_system', 'Manage system report definitions', '', S::CRITICAL, ['reporting.definition.read']),
            P::global('reporting.report.run', 'Run reports (web view)', '', S::NORMAL, ['reporting.catalog.read']),
            P::global('reporting.report.export_pdf', 'Export reports to PDF', '', S::ELEVATED, ['reporting.report.run']),
            P::global('reporting.report.export_xlsx', 'Export reports to Excel', '', S::ELEVATED, ['reporting.report.run']),
            P::global('reporting.report.export_csv', 'Export reports to CSV', '', S::ELEVATED, ['reporting.report.run']),
            P::own('reporting.report.cancel_own', 'Cancel own report runs'),
            P::global('reporting.run.read_all', 'View all report runs', '', S::ELEVATED),
            P::global('reporting.cost_fields.view', 'View cost & valuation fields in reports', '', S::ELEVATED, [], true),
            P::global('reporting.personal_fields.view', 'View personal-data fields in reports', '', S::CRITICAL, [], true),
            P::global('reporting.restricted_fields.view', 'View restricted fields in reports', '', S::CRITICAL, [], true),
        ];
    }
}
