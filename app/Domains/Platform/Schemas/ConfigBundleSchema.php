<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Configuration bundle document (or upload it as the `file` part). */
final class ConfigBundleSchema extends TableSchema
{
    public static function table(): string
    {
        return 'configuration_entries';
    }

    protected static function define(): array
    {
        return [
            'format' => Property::string(60)->nullable()->virtual()->label('Format'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Bundle version'),
            'checksum' => Property::string(64)->nullable()->pattern('/^[a-f0-9]{64}$/')->virtual()->label('Checksum'),
            'platform' => Property::json()->nullable()->virtual()->label('Platform'),
            'company_profile' => Property::json()->nullable()->virtual()->label('Company profile'),
            'brand_kit' => Property::json()->nullable()->virtual()->label('Brand kit'),
            'configuration' => Property::json()->nullable()->virtual()->label('Configuration'),
            'lookups' => Property::json()->nullable()->virtual()->label('Lookups'),
            'enum_labels' => Property::json()->nullable()->virtual()->label('Enum labels'),
            'translation_overrides' => Property::json()->nullable()->virtual()->label('Translations'),
            'templates' => Property::json()->nullable()->virtual()->label('Templates'),
            'organization' => Property::json()->nullable()->virtual()->label('Organization'),
            'catalog_foundations' => Property::json()->nullable()->virtual()->label('Catalog foundations'),
            'partners' => Property::json()->nullable()->virtual()->label('Partners'),
            'document_sequences' => Property::json()->nullable()->virtual()->label('Document numbering'),
            'sod_rules' => Property::json()->nullable()->virtual()->label('SoD rules'),
            'approval_tiers' => Property::json()->nullable()->virtual()->label('Approval tiers'),
            'roles' => Property::json()->nullable()->virtual()->label('Roles'),
            'role_templates' => Property::json()->nullable()->virtual()->label('Role templates'),
            'accounts' => Property::json()->nullable()->virtual()->label('Initial accounts'),
            'report_definitions' => Property::json()->nullable()->virtual()->label('Report definitions'),
            'integrations' => Property::json()->nullable()->virtual()->label('Integrations'),
        ];
    }
}
