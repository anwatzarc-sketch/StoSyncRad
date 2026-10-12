<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup;

use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Setup\Steps;
use LogicException;

/** Maps each setup step to its handler. */
final class SetupHandlers
{
    /** @var array<string, class-string<SetupStepHandler>> */
    public const MAP = [
        'welcome' => Steps\WelcomeStep::class,
        'super_admin' => Steps\SuperAdminStep::class,
        'company_profile' => Steps\CompanyProfileStep::class,
        'brand_kit' => Steps\BrandKitStep::class,
        'localization' => Steps\LocalizationStep::class,
        'identity_security' => Steps\IdentitySecurityStep::class,
        'organization' => Steps\OrganizationStep::class,
        'catalog_foundations' => Steps\CatalogFoundationsStep::class,
        'partners' => Steps\PartnersStep::class,
        'document_numbering' => Steps\DocumentNumberingStep::class,
        'stock_approval_policies' => Steps\StockApprovalPoliciesStep::class,
        'roles_access' => Steps\RolesAccessStep::class,
        'reporting' => Steps\ReportingStep::class,
        'notifications_integrations' => Steps\NotificationsIntegrationsStep::class,
        'opening_data' => Steps\OpeningDataStep::class,
    ];

    public function for(PlatformSetupStep $step): SetupStepHandler
    {
        $class = self::MAP[$step->value] ?? throw new LogicException("No handler for setup step {$step->value}");

        return app($class);
    }
}
